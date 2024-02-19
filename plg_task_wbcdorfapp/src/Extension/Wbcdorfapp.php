<?php

/**
 * @package     Joomla.Plugins
 * @subpackage  Task.Dorfapp
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\Wbcdorfapp\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
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


// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Task plugin with routines to make HTTP requests.
 * At the moment, offers a single routine for GET requests.
 *
 * @since  4.1.0
 */
final class Wbcdorfapp extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * @var string[]
     * @since 4.1.0
     */
    protected const TASKS_MAP = [
        'plg_task_wbcdorfapp_task_get' => [
            'langConstPrefix' => 'PLG_WBCDORFAPP_REQUESTS_TASK_GET_REQUEST',
            'form'            => 'get_requestdorfapp',
            'method'          => 'makeGetRequest',
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
     * The http factory
     *
     * @var    DAlist
     * @since  4.2.0
     */
    protected $DAlist = array();


    /**
     * The Category in Joomla for Dorfapp Articles
     *
     * @var    $AppCategory
     * @since  4.2.0
     */
    protected $AppCategory;


    /**
     * The http factory
     *
     * @var    HttpFactory
     * @since  4.2.0
     */
    private $httpFactory;

    /**
     * The root directory
     *
     * @var    string
     * @since  4.2.0
     */
    private $rootDirectory;

     /**
     * The response url params
     *
     * @var    string
     * @since  4.2.0
     */
    private  $url;

     /**
     * The response url params
     *
     * @var    string
     * @since  4.2.0
     */
    private $source_type;

    /** 
     * The response timeout params
     *
     * @var    string
     * @since  4.2.0
     */
    private $timeout;

    /** 
     * The response auth params
     *
     * @var    string
     * @since  4.2.0
     */
    private $auth;

    /** 
     * The response authType params
     *
     * @var    string
     * @since  4.2.0
     */
    private $authType;

    /** 
     * The response authKey params
     *
     * @var    string
     * @since  4.2.0
     */
    private $authKey;

    /** 
     * The response headers params
     *
     * @var    string
     * @since  4.2.0
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
     * Standard routine method for the get request routine.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event
     *
     * @return integer  The exit code
     *
     * @since 4.1.0
     * @throws \Exception
     */
    protected function makeGetRequest(ExecuteTaskEvent $event): int
    {
        $id     = $event->getTaskId();
        $params = $event->getArgument('params');

        $this->url               = $params->source_url;
        $this->source_type       = $params->source_type;
        $this->AppCategory       = $params->appcategory;
        $responseUrl             = $this->url . '/' . $this->source_type . '/ids';
        $this->timeout           = $params->timeout;
        $this->auth              = (string) $params->auth ?? 0;
        $this->authType          = (string) $params->authType ?? '';
        $this->authKey           = (string) $params->authKey ?? '';
        $this->headers           = [];

        if ($this->auth && $this->authType && $this->authKey) {
            $headers = ['Authorization' => $this->authType . ' ' . $this->authKey];
        }

        try {
            $response = $this->httpFactory->getHttp([])->get($responseUrl, $this->headers, $this->timeout);
        } catch (\Exception $e) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_WBCDORFAPP_REQUESTS_TASK_GET_REQUEST_LOG_TIMEOUT'));

            return TaskStatus::TIMEOUT;
        }

        $responseCode = $response->code;
        $responseBody = $response->body;
    

        // @todo this handling must be rethought and made safe. stands as a good demo right now.
        $responseFilename = Path::clean($this->rootDirectory . "/task_{$id}_response.html");
    
        if ($responseCode == 200) {
            switch ($this->source_type) {
                case 'articles':
                    if ($this->FetchAppNewsArticles($responseBody)) {
                        try {
                            $output = $this->WriteArticles(); 
                            $responseStatus = 'OK';
                            File::write($responseFilename, $output);
                            $this->snapshot['output_file'] = $responseFilename;
                        } catch (\Exception $e) {
                            $this->logTask($e->getMessage(), 'error');
                            return TaskStatus::KNOCKOUT;
                        }

                    } else {
                        $this->logTask($this->getApplication()->getLanguage()->_('PLG_TASK_WBCDORFAPP_NO_ARTICLES_FOUND_IN_SOURCE'), 'warning');
                        $responseStatus = 'NO_ARTICLES_FOUND';
                       
                    };
                    break;                    
                case 'events': 
                    # code noch ergaenzen
                    break;
            }
            
        } else {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_WBCDORFAPP_REQUESTS_TASK_GET_REQUEST_LOG_TIMEOUT'));
            return TaskStatus::TIMEOUT;        
        }
        
        $this->snapshot['output']      = <<< EOF
            ======= Task Output Body =======
            > URL:  $responseUrl
            > Response Code: $responseCode
            > Response: $responseStatus
            EOF;

        $this->logTask(sprintf($this->getApplication()->getLanguage()->_('PLG_WBCDORFAPP_REQUESTS_TASK_GET_REQUEST_LOG_RESPONSE'), $responseCode));
        return TaskStatus::OK;
        
    }

    /**
     * 
     * Get Articles from the API
     *
     *
     *
     * @return 
     *
     * @since 4.1.0
     * @throws \Exception
     */
    protected function FetchAppNewsArticles($responseBody) {
        $output = '';
        $itemIds = json_decode($responseBody);

        if (empty($itemIds)) {
            return $false;
        }

        foreach ($itemIds as $itemId) {
            $responseUrl = $this->url . '/'. $this->source_type .'/'. $itemId;;
            try {
                $responsearticle = $this->httpFactory->getHttp([])->get($responseUrl, $this->headers, $this->timeout);
            } catch (\Exception $e) {
                $this->logTask($this->getApplication()->getLanguage()->_('PLG_WBCDORFAPP_REQUESTS_TASK_GET_REQUEST_LOG_TIMEOUT'));
                return TaskStatus::TIMEOUT;
            }
            if ($responsearticle->code == 200) {
                $item = json_decode($responsearticle->body);

                if (empty($item) || empty($item->text)) { // wenn kein Text vorhanden ist, dann weiter
                    continue;
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
    
                // Bild auslesen
                $this->DAlist[$itemId]['image'] = array();
                $assetReference = $item->coverAssetReference;
                if (!empty($assetReference->assetImage->assetImageUrl)){
                    $this->DAlist[$itemId]['image']['imageSrc']    = $assetReference->assetImage->assetImageUrl;
                    $imageTitle = $assetReference->assetImage->text; 
                    $this->DAlist[$itemId]['image']['imageTitle']  = (!empty($assetReference->assetImage->texte)) ? $assetReference->assetImage->texte : '';
                    $this->DAlist[$itemId]['image']['imageQuelle'] = (!empty($assetReference->assetImage->source)) ? $assetReference->assetImage->source : '';
                }
            }
            
        }
        return true;
    }

    /**
    * 
    * Create Category in Joomla
    * 
    *
    * @return 
    *
    * @since 4.1.0
    * @throws \Exception
    */
    protected function DeleteArticles() {
        $app          = \Joomla\CMS\Factory::getApplication();
        $mvcFactory   = $app->bootComponent('com_content')->getMVCFactory();
        $articleIds   = array();
        $categories   = Categories::getInstance('content');
        $category     = $categories->get($this->AppCategory);
        if (!$category) {
            return false;
        }
        
        // Create the Model to get Article from Category
        $articlesModel = $mvcFactory->createModel('Articles', 'Administrator', ['ignore_request' => true]);
        
        /* Set filter for category id */
        $articlesModel->setState('filter.category_id', $category->id);
        $articlesModel->setState('filter.published', '*');
        $articles = $articlesModel->getItems();
        if (empty($articles)) {
            return true;
        }                
        
        foreach ($articles as $article) {
            // create the model to delete the article
            $articleModel = $mvcFactory->createModel('Article', 'Administrator', ['ignore_request' => true]);
            $articleIds[] = $article->id;
            $article      = [
                'id'            => $article->id,
                'state'         => -2 // -2 = Trashed 
            ];
            if (!$articleModel->save($article)){
                continue;
            }
            
        }
       
        // delete article
        if ( !$articleModel->delete($articleIds)) {
            return false;
        }
        return true;
    }
    /**
     * 
     * Write Articles in Joomla
     * 
     *
     * @return 
     *
     * @since 4.1.0
     * @throws \Exception
     */
    protected function WriteArticles() {
        if ($this->ExistCategory($this->AppCategory) === false) {
            throw new Exception( Text::_('PLG_TASK_WBCDORFAPP_KATEGORIE_NOT_EXIST'));
            return false;
        } 
        if (empty($this->DAlist)) {
            throw new Exception(Text::_('PLG_TASK_WBCDORFAPP_NO_ARTICLES_FOUND_IN_SOURCE'));
            return false;
        }
        // Löschen aller vorhandenen Artikel in dieser Kategorie!
        if ( $this->DeleteArticles() === false) {
            throw new Exception(Text::_('PLG_TASK_WBCDORFAPP_ARTICLE_NOT_DELETE'));
            return false;
        }

        $app          = \Joomla\CMS\Factory::getApplication();
        $mvcFactory   = $app->bootComponent('com_content')->getMVCFactory();

        foreach ($this->DAlist as $item){   
            // create the model to save the article
            $articleModel = $mvcFactory->createModel('Article', 'Administrator', ['ignore_request' => true]);

            // Joomla Images 
            $images = array();
            if (!empty($item['image'])) {
                $images = [
                    'image_intro'               => $item['image']['imageSrc'],
                    'float_intro'               => '',
                    'image_intro_alt'           => $item['image']['imageTitle'],
                    'image_intro_caption'       => '',
                    'image_fulltext'            => $item['image']['imageSrc'],
                    'float_fulltext'            => '',
                    'image_fulltext_alt'        => $item['image']['imageTitle'],
                    'image_fulltext_caption'    => '',
                ];
                $images = json_encode($images);
            }

            $article = [
                'catid'         => $this->AppCategory ,
                'alias'         => \Joomla\CMS\Filter\OutputFilter::stringURLSafe($item['title']),
                'title'         => $item['title'],
                'introtext'     => $item['introtext'],
                'fulltext'      => $item['fulltext'],
                'state'         => 1,
                'images'        => !empty($images) ? $images : '',
                'language'      => '*',
                ];

            if (!$articleModel->save($article))
            {
                $output .= $item['id'] . "\n\n";
                $output .= $item['title'] . "\n\n";
                $output .= 'Beitrag wurde nicht übernommen' . "\n\n";
                $output .= '<------------------------------------------>' . "\n\n";
                continue;          
            }
            $output .= $item['id'] . "\n\n";
            $output .= $item['title'] . "\n\n";
            $output .= 'Beitrag wurde übernommen' . "\n\n";
            $output .= '<------------------------------------------>' . "\n\n";
        }
        
        return $output;
    }
     /**
     * 
     * Check if Category exists
     * 
     *
     * @return 
     *
     * @since 4.1.0
     * @throws \Exception
     */
    protected function ExistCategory($id) {

        $categories = Categories::getInstance('content');
        $category   = $categories->get($id);
        if ($category) {
            return true;
        }
        return false;
    }
    /**
     * 
     * Create Category in Joomla
     * 
     *
     * @return 
     *
     * @since 4.1.0
     * @throws \Exception
     */
    protected function CreateCategory() {}

}