<?php

/**
 * @package     Joomla.Plugins
 * @subpackage  Task.Dorfapp
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\Wbcortsapp\Extension;

defined('_JEXEC') or die();

use DigitalPeak\Component\DPCalendar\Administrator\Extension\DPCalendarComponent;
use DigitalPeak\Component\DPCalendar\Administrator\Helper\DPCalendarHelper;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Path;
use Joomla\Http\HttpFactory;
use Joomla\CMS\Categories\Categories;
use Joomla\CMS\Categories\CategoryNode;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Registry\Registry;
use RuntimeException;
use Exception;


// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Task plugin with routines to make HTTP requests.
 * At the moment, offers a single routine for GET requests.
 *
 * @since  4.1.0
 */
final class Wbcortsapp extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * @var string[]
     * @since 4.1.0
     */
    protected const TASKS_MAP = [
        'plg_task_wbcortsapp_getapicodolistblog' => [
            'langConstPrefix' => 'PLG_TASK_WBCDORFAPP_TASK_APICODO',
            'form'            => 'requestApicodoBlog',
            'method'          => 'GetApicodoListBlog',
        ],
        'plg_task_wbcortsapp_getapicodolistevent' => [
            'langConstPrefix' => 'PLG_TASK_WBCDORFAPP_TASK_APICODOEVENT',
            'form'            => 'requestApicodoEvents',
            'method'          => 'GetApicodoListEvent',
        ],
    ];

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return string[]
     *
     * @since 4.1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * @var boolean
     * @since 4.1.0
     */
    protected $autoloadLanguage = true;

    /**
     * @var String
     * @since 4.1.0
     */
    protected $responseStatus;

    /**
     *  List Array for Dorfapp Articles / Events
     *
     * @var    Array
     * @since  4.1.0
     */
    protected $DAlist = array();

    /**
     * The Category in Joomla for Dorfapp Articles
     *
     * @var    String
     * @since  4.1.0
     */
    protected $AppCategory;

     /**
     * Kalender Id DPCalendar
     *
     * @var    String
     * @since  4.1.0
     */
    protected $AppCalCategory;

     /**
     * The Categories in Ortsapp 
     *
     * @var    Array
     * @since  4.1.0
     */
    protected $ApicodoChapters = array();

    /**
     * The Selected Chapter ID's
     *
     * @var    Array
     * @since  4.1.0
     */
    protected $SelectedChapters = array();

    /**
     * The Image Directory
     * @var    String
     * 
     */
    protected $ImagesPath = '/images/wbcortsapp/';

    /**
     * Max Endyear for Events
     *
     * @var    String
     * @since  4.1.0
     */
    protected $MaxEndYear;

    /**
     * Rule Frequency
     *
     * @var    Array
     * @since  4.1.0
     */

     protected $ruleFrequency = array(
        7 => 'YEARLY',
        6 => 'MONTHLY',
        5 => 'WEEKLY',
        4 => 'DAILY',
        3 => 'HOURLY',
        2 => 'MINUTELY',
        1 => 'SECONDLY'
    );

    /**
     * Days of Week for RRule
     *
     * @var   Array
     * @since  4.1.0
     */

     protected $daysOfWeek = array(
        0 => 'SU',
        1 => 'MO',
        2 => 'TU',
        3 => 'WE',
        4 => 'TH',
        5 => 'FR',
        6 => 'SA'
    );
    /**
     * Days of Week Offset for RRule
     *
     * @var   Array
     * @since  4.1.0
     */

     protected $ByDayofWeek = array( -1,1,2,3,4,5 );
       
    /**
     * The site Url for API Call DPCalender
     *
     * @var    String
     * @since  4.1.0
     */
    protected $apiSiteUrl;

    /**
     * The http factory
     *
     * @var    HttpFactory
     * @since  4.1.0
     */
    private $httpFactory;

    /**
     * The root directory
     *
     * @var    String
     * @since  4.1.0
     */
    private $rootDirectory;

     /**
     * The response url params
     *
     * @var    String
     * @since  4.1.0
     */
    private  $url;

     /**
     * The response url params
     *
     * @var    String
     * @since  4.1.0
     */
    private $source_type;
  
    /** 
     * The response timeout params
     *
     * @var    String
     * @since  4.1.0
     */
    private $timeout;

    /** 
     * The response auth params
     *
     * @var    String
     * @since  4.1.0
     */
    private $auth;

    /** 
     * The response authType params
     *
     * @var    String
     * @since  4.1.0
     */
    private $authType;

    /** 
     * The response authKey params
     *
     * @var    String
     * @since  4.1.0
     */
    private $authKey;

    /** 
     * The response Joomla Token site
     *
     * @var    String
     * @since  4.1.0
     */
    private $Token;

    /** 
     * The response authKey params
     *
     * @var    String
     * @since  4.1.0
     */
    private $AuthType;

    /** 
     * The response headers params
     *
     * @var    String
     * @since  4.1.0
     */
    private $headers;

    /**
     * Constructor.
     *
     * @param   DispatcherInterface  $dispatcher     The dispatcher
     * @param   array                $config         An optional associative array of configuration settings
     * @param   HttpFactory          $httpFactory    The http factory
     * @param   string               $rootDirectory  The root directory to store the output file in
     *
     * @since   4.2.0
     */
    public function __construct(DispatcherInterface $dispatcher, array $config, HttpFactory $httpFactory, string $rootDirectory)
    {
        parent::__construct($dispatcher, $config);

        $this->httpFactory   = $httpFactory;
        $this->rootDirectory = $rootDirectory;
    }

    /**
     * Standard routine method for Get Apicodo ListBlog.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event
     *
     * @return integer  The exit code
     *
     * @since 4.1.0
     * @throws \Exception
     */
    protected function GetApicodoListBlog(ExecuteTaskEvent $event): int
    {
        $id     = $event->getTaskId();
        $params = $event->getArgument('params');
        $task   = $event->getArgument('subject');

        $this->url                    = $params->source_url;
        $this->AppCategory            = $params->appcategory;
        $this->timeout                = $params->timeout;
        $this->auth                   = (string) $params->auth ?? 0;
        $this->authType               = (string) $params->authType ?? '';
        $this->authKey                = (string) $params->authKey ?? '';
        $this->headers                = [];
        $this->Token                  = (string) $this->params->get('Token');
        $this->AuthType               = (string) $this->params->get('AuthType');
        $this->source_type            = 'articles';

        $http = (new HttpFactory())->getAvailableDriver();   
        // Url Joomla API Call     
        $this->apiSiteUrl   = Uri::root(). 'api/index.php/v1';
        
        // wenn nur bestimmte Kategorien aus der OrtsApp geholt werden sollen
        if ($params->apicodo_chapter) {
            $this->SelectedChapters = explode(',',$params->apicodo_chapter);
        
            // Kategorien aus der OrtsApp
            $responseDAUrl = $this->buildResponseDAUrl('chapters');
            if (!$responseDAUrl){
                return TaskStatus::KNOCKOUT;
            }

            // Liste aller Artikel aus der Ortsapp holen.
            try {
                $response = $this->httpFactory->getHttp([])->get($responseDAUrl, $this->headers, $this->timeout);
            } catch (\Exception $e) {
                $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_CHAPTERS_LOG_TIMEOUT'));
                return TaskStatus::TIMEOUT;
            } 

            if ($response->code == 200) {
                // Chapters / Kategorien aus Apicodo
                $Chapters = json_decode($response->body);
                $this->SelectChapterSlugs($Chapters);
            }
        }

        // Request URL Ortsapp Liste aller Artikel / Events
        $responseDAUrl  = $this->buildResponseDAUrl('list');
        if (!$responseDAUrl){
            return TaskStatus::KNOCKOUT;
        }

        if ($this->auth && $this->authType && $this->authKey ) {
            $headers = ['Authorization' => $this->authType . ' ' . $this->authKey ];
        }
        // Liste aller Artikel / Events aus der Dorfapp holen.
        try {
            $response = $this->httpFactory->getHttp([])->get($responseDAUrl, $this->headers, $this->timeout);
        } catch (\Exception $e) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_LIST_LOG_TIMEOUT'));
            return TaskStatus::TIMEOUT;
        }

        $responseCode = $response->code;
        $responseBody = $response->body;
    
        if ($responseCode == 200) {
            if ($this->BuildItemArray($responseBody)) {
                $this->WriteArticles();
                return TaskStatus::OK;
            } 
            
        } else  {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_LIST_LOG_ERROR'));
            return TaskStatus::TIMEOUT;
        }
    }

    /**
     * Standard routine method for Apicodo ListEvent.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event
     *
     * @return integer  The exit code
     *
     * @since 4.1.0
     * @throws \Exception
     */
    protected function GetApicodoListEvent(ExecuteTaskEvent $event): int 
    {
        $id     = $event->getTaskId();
        $params = $event->getArgument('params');
        $task   = $event->getArgument('subject');

        $this->url                    = $params->source_url;
        $this->AppCalCategory         = $params->appcalcategory;
        $this->timeout                = $params->timeout;
        $this->MaxEndYear             = $params->maxendyear;
        $this->auth                   = (string) $params->auth ?? 0;
        $this->authType               = (string) $params->authType ?? '';
        $this->authKey                = (string) $params->authKey ?? '';
        $this->headers                = [];
        $this->Token                  = (string) $this->params->get('Token');
        $this->AuthType               = (string) $this->params->get('AuthType');
        $this->source_type            = 'eventcalendar';
        
        // prüfen ob die Komponente DPCalendar installiert ist
        $component_name = 'com_dpcalendar';

        if (!ComponentHelper::isEnabled($component_name, true)) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_DPCALENDAR_NOT_INSTALLED'));
            return TaskStatus::NO_RUN;
        }

        $http = (new HttpFactory())->getAvailableDriver();   
        // Url Joomla API Call     
        $this->apiSiteUrl   = Uri::root(). 'api/index.php/v1';
        
        // wenn nur bestimmte Kategorien aus der OrtsApp geholt werden sollen
        if ($params->apicodo_chapter) {
            $this->SelectedChapters = explode(',',$params->apicodo_chapter);
        
            // Kategorien aus der OrtsApp
            $responseDAUrl = $this->buildResponseDAUrl('chapters');
            if (!$responseDAUrl){
                return TaskStatus::KNOCKOUT;
            }

            // Liste aller Artikel aus der Ortsapp holen.
            try {
                $response = $this->httpFactory->getHttp([])->get($responseDAUrl, $this->headers, $this->timeout);
            } catch (\Exception $e) {
                $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_CHAPTERS_LOG_TIMEOUT'));
                return TaskStatus::TIMEOUT;
            } 

            if ($response->code == 200) {
                // Chapters / Kategorien aus Apicodo
                $Chapters = json_decode($response->body);
                $this->SelectChapterSlugs($Chapters);
            }
        }

        // Request URL Ortsapp Liste aller Artikel / Events
        $responseDAUrl  = $this->buildResponseDAUrl('list');
        if (!$responseDAUrl){
            return TaskStatus::KNOCKOUT;
        }

        if ($this->auth && $this->authType && $this->authKey ) {
            $headers = ['Authorization' => $this->authType . ' ' . $this->authKey ];
        }
        // Liste aller Artikel / Events aus der Dorfapp holen.
        try {
            $response = $this->httpFactory->getHttp([])->get($responseDAUrl, $this->headers, $this->timeout);
        } catch (\Exception $e) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_LIST_LOG_TIMEOUT'));
            return TaskStatus::TIMEOUT;
        }

        $responseCode = $response->code;
        $responseBody = $response->body;
    
        if ($responseCode == 200) {
            if ($this->BuildItemArray($responseBody)) {
                if ($this->WriteEvents() == true) {
                    return TaskStatus::OK;
                }
                return TaskStatus::KNOCKOUT;
            } 
            
        } else  {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_LIST_LOG_ERROR'));
            return TaskStatus::TIMEOUT;
        }
    }

    /**
     * 
     * Build Request URL     *
     *
     * @return 
     *
     * @since 4.1.0
     * 
     */
    protected function buildResponseDAUrl($typ, $itemid = null, $apicodoChapter = null) {
        
        $responseDAUrl = $this->url;

        if ($this->source_type == 'articles') {
            switch ($typ) {
                case 'list':
                    $responseDAUrl .= '/'. $this->source_type;
                    return $responseDAUrl;
                case 'detail':
                    $responseDAUrl .= '/'. $this->source_type .'/'. $itemid;
                    return $responseDAUrl;
            }
        }

        if ($this->source_type == 'eventcalendar') {
            switch ($typ) {
                case 'list':
                        $responseDAUrl .= '/'. $this->source_type .'/eventlist';
                        return $responseDAUrl;
                case 'detail':
                        $responseDAUrl .= '/'. $this->source_type .'/'. $itemid .'?forEdit=false';
                        return $responseDAUrl;
            }
        }

        if ($typ == 'chapters'){
            $responseDAUrl .= '/'. $typ .'/'. $apicodoChapter;
            return $responseDAUrl;
        }

        $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_ERROR_CREATE_RESPONSE_URL'));
        return false;
    }

    /**
     * 
     * Return Array with selected Items from Apicodo
     * $list =  JSON String aller Artikel oder Events 
     *
     * @return 
     *
     * @since 4.1.0
     * 
     */
    protected function BuildItemArray($list) {

        $itemIds = json_decode($list);

        if (empty($itemIds)) {
            return $false;
        }
        // Details Artikel / Events
        $save_itemid = 0;

        // alle Artikel / Events durchgehen
        foreach ($itemIds as $itemId) {

            $ID = $itemId->id;

            // Quelle Eventscalender Id aus Array für die Detailansicht des Events.
            // ID kann mehrfach vorkommen, wenn es sich um Serientermine handelt, da immer ein Array.

            if ($this->source_type == 'eventcalendar' && $save_itemid == $itemId->id) {
                continue;
            } else {
                $save_itemid = $itemId->id;
                $ID = $itemId->id;
            }
           
            $responseDAUrl = $this->buildResponseDAUrl('detail', $ID);

            if (!$responseDAUrl){
                return false;
            } else {
                try {
                    $responsearticle = $this->httpFactory->getHttp([])->get($responseDAUrl, $this->headers, $this->timeout);
                } catch (\Exception $e) {
                    $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_ARTICLE_LOG_TIMEOUT'));
                    return false;
                }
            }

            // wenn ok Array aus der liste erstellen.
            if ($responsearticle->code == 200) {
                switch ($this->source_type) {
                    case 'articles':
                        $this->AppArticle($responsearticle->body, $ID);
                        break;
                    case 'eventcalendar':
                        $this->AppEvent($responsearticle->body, $ID);
                        break;
                }
            }
            
        }
        return true;
    }

    /**
     * 
     * Create DAList Array 
     * Aufbereiten der Artikel aus OrtsApp für Joomla 
     *
     * @return  
     *
     * @since 4.1.0
     * 
     */
    protected function AppArticle($responsearticlebody, $itemId) {

        $item = json_decode($responsearticlebody);

        if (empty($item) || empty($item->text)) { // wenn kein Text vorhanden ist, dann weiter
            return false;
        }

        // prüfen ob der Datensatz die ausgeählte Chapter ID hat, wenn Chapter vorhanden sind
        if ( !empty($item->chapterSlugs) && $this->CheckChapter($item) === false ){ 
            return;
        }

        $this->DAlist[$itemId]['id'] = $item->id;
        $this->DAlist[$itemId]['title'] = $item->text;
        $this->DAlist[$itemId]['fulltext'] = '';

        if (!empty($item->summary)) {
            $this->DAlist[$itemId]['introtext'] = $item->summary;
            $this->DAlist[$itemId]['fulltext']  = (!empty($item->content)) ? $item->content : '';
        } else {
            $this->DAlist[$itemId]['introtext'] = (!empty($item->content)) ? $item->content : '';
        }
        $this->DAlist[$itemId]['spitzmarke'] = (!empty($item->heading)) ? $item->heading : '';

        // Einleitungsbild $coverAssetReference
        $this->DAlist[$itemId]['image'] = array();
        $coverAssetReference = $item->coverAssetReference;
        if (!empty($coverAssetReference->assetImage->assetImageUrl)){
            if (!$this->SaveMedien($coverAssetReference, $itemId) ) {
                return false;
            }
        }
        // Bildergalerien wenn vorhanden
        $assetReferences = $item->assetReferences;
        $i = 0;
        if (!empty($assetReferences)) {
            foreach ($assetReferences as $assetReference) {
                if (!empty($assetReference->assetImage->assetImageUrl)){
                    $galerieimage = array();
                    $galerieimage['imageSrc'] = $assetReference->assetImage->assetImageUrl;
                    $galerieimage['imageTitle']  = (!empty($assetReference->assetImage->text)) ? $assetReference->assetImage->text : '';
                    $galerieimage['imageQuelle'] = (!empty($assetReference->assetImage->source)) ? TEXT::_('PLG_TASK_WBCDORFAPP_TASK_APICODO_ARTICLE_IMAGE_QUELLE').$assetReference->assetImage->source : '';
                    $this->DAlist[$itemId]['galerieimage-'.$i] = $galerieimage;
                }
                $i++;
            }
            
        }
        return true; 
    }

    /**
    * 
    * Delete Articles in OrtsAPP Category
    * Com_content
    *
    * @return 
    *
    * @since 4.1.0
    * 
    */
    protected function DeleteArticles() {
        
        // Create the Model to get Article from Category
        $app           = Factory::getApplication();
        $component     = $app->bootComponent('com_content');
        
        $db             = Factory::getContainer()->get('DatabaseDriver');
		$query          = $db->getQuery(true)->select('e.id')->from('#__content e');
		$query->where('e.catid in (' . $this->AppCategory . ')');

		$db->setQuery($query);

		$result = $db->loadColumn();

        if (empty($result)) { // Keine Artikel vorhanden
            return true;
        }                

        $this->logTask((is_countable($result) ? count($result) : 0) . $this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_EVENTS_DELETE'), 'debug');
        
        // Delete Articles
		$component->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true])->publish($result, -2);
		$component->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true])->delete($result);
		return true;
       
    }

    /**
     * 
     * Write Articles in Joomla
     * Com_content
     *
     * @return 
     *
     * @since 4.1.0
     */
    protected function WriteArticles() {

        if (empty($this->DAlist)) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_NO_ARTICLES_FOUND_IN_SOURCE'));
            return true;
        }
        $app             = Factory::getApplication();
        $component_name  = 'com_content';
        $categoriesModel =  $app->bootComponent('categories')->getMVCFactory()->createModel('Categories', 'Administrator', ['ignore_request' => true]);
        $categoriesModel->setState('filter.extension', $component_name);
        $categoriesModel->setState('filter.published', 'all');

        $existingCalendars = $categoriesModel->getItems();
        $catid = $this->AppCategory;
        $category = array_filter($existingCalendars, static fn ($e): bool => $e->id == $catid);
       
        if (!$category) {
            // Kategorie erstellen, wenn nicht vorhanden
            $category = CreateCategory($component_name);
            $catid    = $category->id;
        } else {
             // Löschen aller vorhandenen Artikel in dieser Kategorie!
            if ( !$this->DeleteArticles() ) {
                return false;
            }
        } 
                    
        $mvcFactory   = $app->bootComponent('com_content')->getMVCFactory();

        foreach ($this->DAlist as $item){   
            // create the model to save the article
            $articleModel = $mvcFactory->createModel('Article', 'Administrator', ['ignore_request' => true]);
            $articleModel->setState('params', new Registry());

            // Joomla Images 
            $images = array();
            $img_alt = '';
            $img_caption = '';
            $img_no_alt = '';

            if (!empty($item['image'])) {

                if ( empty($item['image']['imageTitle']) && empty($item['image']['imageQuelle'])) {
                    $img_no_alt = 1;
                } 
                if (!empty($item['image']['imageTitle'])) {
                    $img_alt = $item['image']['imageTitle'];
                } 
                if (!empty($item['image']['imageQuelle'])) {
                    $img_caption = $item['image']['imageQuelle'];
                    $img_alt    .= $item['image']['imageQuelle'];
                }

                $images = [
                    'image_intro'               => $item['image']['imageSrc'],
                    'image_intro_alt'           => $img_alt,
                    'image_intro_alt_empty'     => $img_no_alt,
                    'float_intro'               => '',
                    'image_intro_caption'       => $img_caption,
                    'image_fulltext'            => $item['image']['imageSrc'],
                    'image_fulltext_alt'        => $img_alt,
                    'float_fulltext'            => '',
                    'image_fulltext_alt_empty'  => $img_no_alt,
                    'image_fulltext_caption'    => $img_caption,
                ];
                $images = json_encode($images);
            }

            $article = [
                'catid'         => $catid,
                'alias'         => \Joomla\CMS\Filter\OutputFilter::stringURLSafe($item['title']),
                'title'         => $item['title'],
                'introtext'     => $item['introtext'],
                'fulltext'      => $item['fulltext'],
                'state'         => 1,
                'images'        => !empty($images) ? $images : '',
                'language'      => '*',
                ];

            try {
                $articleModel->save($article);
                $this->logTask($item['title'].': '.$this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_ARTICLE_SAVED'));
            } catch (\Exception $e) {
                $this->logTask($item['title'].': '.$this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_ARTICLE_ERROR_SAVE'));
                return TaskStatus::KNOCKOUT;
            }
        }
        return true;
    }
     
    /**
     * 
     * Create New Category in Joomla
     * 
     *
     * @return 
     *
     * @since 4.1.0
     * @throws \Exception
     */
    protected function CreateCategory($component_name) {
        
        $data                = [];
        $data['id']          = 0;
        $data['title']       = 'OrtsAPP';
        $data['description'] = 'Import OrtsAPP';
        $data['extension']   = $component_name;
        $data['parent_id']   = 1;
        $data['published']   = 1;
        $data['language']    = '*';

        $app = Factory::getApplication();
        $model =  $app->bootComponent('categories')->getMVCFactory()->createModel('Category', 'Administrator');
        $model->save($data);
        $category = $model->getItem($model->getState('category.id'));
        return $category;
    }

     /**
     *  Create DAList Array 
     *  Veranstaltungen/ Events aus OrtsApp  
     *  für DPCalender aufbereiten
     *
     *
     * @return 
     *
     * @since 4.1.0
     */
    protected function AppEvent($responsearticlebody, $itemId) {
       
        $item = json_decode($responsearticlebody);

        if (empty($item)) { // wenn kein Text vorhanden ist, dann weiter
            return;
        }

        $this->DAlist[$itemId]['calid'] = $this->AppCalCategory;
        $this->DAlist[$itemId]['title'] = $item->summary;

        // tag <asset> aus dem Code entfernen.
        $pattern = '/<asset.*?<\/asset>/si';
        $item->htmlDescription = preg_replace($pattern, '', $item->htmlDescription);
        $this->DAlist[$itemId]['description'] = $item->htmlDescription;

        // Event Start und Endzeit

        // Datum für DPCalender aufbereiten
        $dateTime = new Date($item->start);
        $this->DAlist[$itemId]['start_date'] = $dateTime->format('Y-m-d H:i:s'); 
        $dateTime = new Date($item->end);
        $this->DAlist[$itemId]['end_date'] = $dateTime->format('Y-m-d H:i:s');
       
        // Ganztägiger Event 
        $this->DAlist[$itemId]['all_day'] = ($item->isAllDay === true ) ? 1 : 0;
        // Keine Endzeit anzeigen
        $this->DAlist[$itemId]['show_end_time'] = ( $item->isOpenEnd  === true ) ? 1 : 0; 
        
        // Serientermine
        $rrule = $this->GetRRule($item->recurrenceRule);
        $this->DAlist[$itemId]['rrule'] = $rrule;
        $this->DAlist[$itemId]['restrictionType'] = $item->recurrenceRule->restrictionType;
        $this->DAlist[$itemId]['evaluationMode']  = $item->recurrenceRule->evaluationMode;
        $this->DAlist[$itemId]['firstDayOfWeek']  = $item->recurrenceRule->firstDayOfWeek;

        // Bild auslesen
        if (!empty( $item->coverImage->assetImageUrl )) {
            $this->DAlist[$itemId]['image'] = $this->GetImages($item->coverImage);
        }                
        return;        
    }

    /**
     * 
     * Bilder auslesen
     *
     * @return 
     *
     * @since 4.1.0
     * 
     */
    protected function GetImages($coverImage) {
        
        $imagearray = array();
        
        $imagearray['imageSrc']     = $coverImage->assetImageUrl;
        $imagearray['imageTitle']   = (!empty($coverImage->text)) ? $coverImage->text : '';
        $imagearray['imageQuelle']  = (!empty($coverImage->source)) ? TEXT::_('PLG_TASK_WBCDORFAPP_TASK_APICODO_ARTICLE_IMAGE_QUELLE').$coverImage->source : '';
        $imagearray['width']        = $coverImage->width;
        $imagearray['height']       = $coverImage->height;
        
        return $imagearray;
    }

     /**
     * 
     * Events in DP Calendar schreiben
     * Throws Exception 
     * @return 
     *
     * @since 4.1.0
     * 
     */
    protected function WriteEvents() {

        if (empty($this->DAlist)) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_EVENTS_NOT_FOUND_IN_SOURCE'));
            return true;
        }

        $app            = Factory::getApplication();
        $component      = $app->bootComponent('dpcalendar');
        $component_name = 'com_dpcalendar';

        // Prüfen ob die Kalender Kategorie existiert 
        $categoriesModel =  $app->bootComponent('categories')->getMVCFactory()->createModel('Categories', 'Administrator', ['ignore_request' => true]);
        $categoriesModel->setState('filter.extension', 'com_dpcalendar');
        $categoriesModel->setState('filter.published', 'all');

        $existingCalendars = $categoriesModel->getItems();
        $catid = $this->AppCalCategory;
        $category = array_filter($existingCalendars, static fn ($e): bool => $e->id == $catid);
        
        if (!$category) { // wenn nicht Kategorie erstellen
            $category = CreateCategory($component_name);
            $catid    = $category->id;
        } else {
            // Alle vorhandenen Events in dieser Kategorie löschen!

            if (!$this->deleteEventsortsapp()) {
                return false;
            }
        }

        // Events schreiben
        foreach ( $this->DAlist as $item ) {

            $Eventmodel = $component->getMVCFactory()->createModel('Form', 'Site');

            // schreibe Event
            $data = array();
            $data['title']         = $item['title'];
            $data['catid']         = $catid;
            $data['description']   = $item['description'];
            $data['start_date']    = $item['start_date'];
            $data['end_date']      = $item['end_date'];
            $data['rrule']         = $item['rrule'];
            $data['all_day']       = $item['all_day'];
            $data['show_end_time'] = $item['show_end_time'];
            $data['location']      = [];
            $data['alias']         =  ApplicationHelper::stringURLSafe($item['title']);
            $data['url']           = '';
            $data['color']         = '';
            $data['xreference']    = '';
            $data['params']        = [];
            $data['metadata']      = [];
            $data['earlybird']     = [];
            $data['user_discount'] = [];

            if (!empty($item['image'])) { 
                $images = array();
                // Intro Image
                $images['image_intro'] = $item['image']['imageSrc'];
                $images['image_intro_width'] = $item['image']['width'];
                $images['image_intro_height'] = $item['image']['height'];
                $images['image_intro_alt'] = $item['image']['imageTitle'];
                $images['image_intro_caption'] = $item['image']['imageQuelle'];
                if (!empty($images['image_intro_width']) && !empty($images['image_intro_height']) ) {
                    $images['image_intro_dimensions'] = 'width= \"'.$item['image']['width']. '\" height= \"'.$item['image']['height'].'\"';
                } else {
                    $images['image_intro_dimensions'] = '';
                }
                // Fulltext Image
                $images['image_full'] = $item['image']['imageSrc'];
                $images['image_full_width'] = $item['image']['width'];
                $images['image_full_height'] = $item['image']['height'];
                $images['image_full_alt'] = $item['image']['imageTitle'];
                $images['image_full_caption'] = $item['image']['imageQuelle'];
                if (!empty($images['image_full_width']) && !empty($images['image_full_height']) ) {
                    $images['image_full_dimensions'] = 'width= \"'.$item['image']['width']. '\" height= \"'.$item['image']['height'].'\"';
                } else {
                    $images['image_full_dimensions'] = '';
                }
            }
            $data['images'] = $images;

		    $Eventmodel->getState();
           
            try {
                $Eventmodel->save($data);
                $this->logTask($item['title'].': '.$this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_EVENT_SAVED'));
            } catch (\Exception $e) {
                $this->logTask($item['title'].': '.$this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_EVENT_NOT_SAVED'));
                return TaskStatus::KNOCKOUT;
            }
        }
        return true;
    }

    
    /**
     * 
     * Alle Events der Kategorie in DPCalendar löschen
     *
     * @return 
     *
     * @since 4.1.0
     * 
    */
    protected function deleteEventsortsapp()
	{
		$app        = $this->getApplication();
		$component  = $app->bootComponent('dpcalendar');
		$db         = Factory::getContainer()->get('DatabaseDriver');
		$query      = $db->getQuery(true)->select('e.id')->from('#__dpcalendar_events e');
		$query->where('e.catid in (' . $this->AppCalCategory . ') ');
		$db->setQuery($query);

		$result = $db->loadColumn();

		if ($result === false) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_ERROR_DELETE_EVENTS'));
			return false;
		}

		if (!$result) { // Keine Events vorhanden
			return true;
		}

        // Alle Events der Kategorie in den Papierkorb verschieben
		$component->getMVCFactory()->createModel('Event', 'Administrator', ['ignore_request' => true])->publish($result, -2);
	
        // Events löschen. Alle Original Events und normale Termine löschen
        $query      = $db->getQuery(true)->select('e.id')->from('#__dpcalendar_events e');
		$query->where('e.catid in (' . $this->AppCalCategory . ') AND e.state = -2 AND e.original_id IN ( -1, 0 )');
		$db->setQuery($query);

		$result = $db->loadColumn();

        if(!$component->getMVCFactory()->createModel('Event', 'Administrator', ['ignore_request' => true])->delete($result)) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_EVENTS_NOT_DELETED'));
        } else {
            $this->logTask( (is_countable($result) ? count($result) : 0) . $this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_TASK_APICODO_EVENTS_DELETE'), 'debug');
		}
        return true;
	}

    /**
     * 
     *  API Response 
     *
     * @return 
     *
     * @since 4.1.0
     * 
     */

    protected function makeResponse($method, $component, $datastring = null, $filter = null) {
       
        $http = (new HttpFactory())->getAvailableDriver();  

        if (empty($this->Token)) {
            return false;
        }

        if ( $this->AuthType && $this->Token ) {
            $headers = ['Authorization' => $this->AuthType . ' ' .  $this->Token ];
        }

        // Timeout in seconds
        $timeout = 30;
        
        // Set path for creating an article it will set the current uri path part
        if ($filter !== null) {
            $uri = $this->apiSiteUrl.'/'. $component . $filter;
        } else {    
            $uri = $this->apiSiteUrl.'/'. $component;
        }
        $uri = new Uri($uri);

        // Will be a PSR-7 compatible Response
        $response = $http->request($method, $uri, $datastring, $headers, $timeout); 
        
        return $response;   
    }

    /**
     * 
     * RRule für Serientermine ermitteln
     *
     * @return RRule
     *
     * @since 4.1.0
     * 
     */
    protected function GetRRule($recurrenceRule = array()) {
        $RRule = '';
        if ($recurrenceRule->frequency == 0 ) {
            return $RRule;
        }
        $RRule = 'FREQ=' . $this->ruleFrequency[$recurrenceRule->frequency] . ';';
        $RuleItem = '';
        
        // BYDAY
        if (!empty($recurrenceRule->byDay)) {
            foreach ($recurrenceRule->byDay as $day) {
                if (in_array($day->offset, $this->ByDayofWeek)) {
                    $RuleItem .= $day->offset;
                }
                $RuleItem .= $this->daysOfWeek[$day->dayOfWeek] . ',';
            }
            $RuleItem = rtrim($RuleItem, ','); // Entfernt das letzte Komma
            $RRule .= 'BYDAY=' . $RuleItem . ';';
        }

        // BYMONTHDAY
        if (!empty($recurrenceRule->byMonthDay)) {
            $RuleItem = implode(',', $recurrenceRule->byMonthDay) . ';';
            $RRule .= 'BYMONTHDAY=' . $RuleItem;           
        }
        // BYYEARDAY
        if (!empty($recurrenceRule->byYearDay)) {
            $RuleItem = implode(',', $recurrenceRule->byYearDay) . ';';
            $RRule .= 'BYYEARDAY=' . $RuleItem;               
        }
        // BYWEEKNO
        if (!empty($recurrenceRule->byWeekNo)) {
            $RuleItem = implode(',', $recurrenceRule->byWeekNo) . ';';
            $RRule .= 'BYWEEKNO =' . $RuleItem;               
        }
        // BYMONTH
        if (!empty($recurrenceRule->byMonth)) {
            $RuleItem = implode(',', $recurrenceRule->byMonth) . ';';
            $RRule .= 'BYMONTH =' . $RuleItem;               
        }
        
        $RRule .= 'INTERVAL=' . $recurrenceRule->interval . ';';
        
        // Endzeit für Serientermine
        $untilString = $recurrenceRule->until;

        //  DateTime-Objekt aus dem String
        $dateTime = new Date($untilString);

        // Extrahieren Datum
        $datum   = $dateTime->format('Ymd'); 
        $uhrzeit = $dateTime->format('His');

        if ($datum == '00010101') {
            $datum = date('Y') + $this->MaxEndYear . '1231';
            $uhrzeit = '000000';
        }
        $untilString = $datum . 'T' . $uhrzeit . 'Z';
        $RRule .= 'UNTIL=' . $untilString;
        
        return $RRule;
    }

     /**
     * 
     * Prüft ob die ID in der Liste der Chapters vorhanden ist
     * und gibt Chapter slug und shortname zurück
     * $Chapters = Apicodo Chapters
     *
     * @return true/false
     *
     * @since 4.1.0
     * 
     */
    protected function SelectChapterSlugs($Chapters) {

        if (empty($Chapters)) {
            return;
        }

        foreach ($Chapters as $item) {
            // Prüfen ob Chapter ID des Artikel vorhanden ist
            foreach ($item['chapters'] as $chapter ) {
                if (in_array($chapter->id, $this->SelectedChapters)) {
                    $this->ApicodoChapters[] = array('id' => $chapter->id, 'slug' => $chapter->slug, 'shortname' => $chapter->shortname);
                }
            }   
        }
        return;
    }
    /**
     * 
     * Prüfen ob der Datensatz die ausgeählte Chapter ID hat
     *
     * @return true/false
     *
     * @since 4.1.0
     * 
     */
    protected function CheckChapter($item) {

        // es gibt keine Chapters in der OrtsApp
        if (empty($this->ApicodoChapters)) { 
            return true;
        }

        if (!isset($item->chapterSlugs) || empty($item->chapterSlugs)) {
            return true;
        }
            
        // alle Chapters Slugs des Artikels durchlaufen
        foreach ($item->chapterSlugs as $chapterSlug) {
            // durchlaufen aller Apicodo Chapters
            foreach ($this->ApicodoChapters as $ApicodoChapter) {
                // Prüfen ob Chapter ID des Artikel in der auswahl vorhanden ist
                if ($chapterSlug == $ApicodoChapter['slug']) {
                    return true;
                }
            }
        }
        return false;
    }
    /**
     * 
     * Speichert die Bilder in lokalem Verzeichnis
     *
     * @return true/false
     *
     * @since 4.1.0
     * 
     */
    protected function SaveMedien($coverAssetReference, $item) {

        $imageUrl = $coverAssetReference->assetImage->assetImageUrl;
        $imageTitle = !empty($coverAssetReference->assetImage->text) ? $coverAssetReference->assetImage->text : '';
        $imageQuelle = !empty($coverAssetReference->assetImage->source) ? TEXT::_('PLG_TASK_WBCDORFAPP_TASK_APICODO_ARTICLE_IMAGE_QUELLE') . $coverAssetReference->assetImage->source : '';
    
        // Verzeichnis für Bilder
        $imagesDir = JPATH_ROOT . $this->$ImagesPath;
        if (!file_exists($imagesDir)) {
            mkdir($imagesDir, 0755, true);
        }
    
        // Bild herunterladen und lokal speichern
        $imageContent = file_get_contents($imageUrl);

        if ($imageContent === false) {
            return false;
        }
    
        // Dateiname für das Bild
        $imagePath = $imagesDir . basename($imageUrl);
        // Überprüfen, ob die Datei bereits vorhanden ist
        if (!file_exists($imagePath)) {
            // Datei ist noch nicht  vorhanden, dann speichern
            $saveResult = file_put_contents($imagePath, $imageContent);
            if ($saveResult === false) {
                return false;
            }
        }
        
        $this->DAlist[$itemId]['image']['imageSrc']    = Uri::root(true) . $this->$ImagesPath . basename($imageUrl);
        $this->DAlist[$itemId]['image']['imageTitle']  = $imageTitle;
        $this->DAlist[$itemId]['image']['imageQuelle'] = $imageQuelle;
        return true;
    }   
} 
