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
    private $url;

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
        $responseUrl             = $this->url . '/' . $this->source_type . '/ids';
        $this->timeout           = $params->timeout;
        $this->auth              = (string) $params->auth ?? 0;
        $this->authType          = (string) $params->authType ?? '';
        $this->authKey           = (string) $params->authKey ?? '';
        $this->headers                 = [];

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

        
        try {
            if ($this->source_type == 'articles' && $responseCode == 200) {
                $output = $this->AppNewsArticles($responseBody); 
                File::write($responseFilename, $output);
                $this->snapshot['output_file'] = $responseFilename;
                $responseStatus                = 'OK';
            } else {
                return TaskStatus::KNOCKOUT;
            }        
           
        } catch (\Exception $e) {
            $this->logTask($this->getApplication()->getLanguage()->_('PLG_WBCDORFAPP_REQUESTS_TASK_GET_REQUEST_LOG_UNWRITEABLE_OUTPUT'), 'error');
            $responseStatus = 'NOT_SAVED';
        }

        $this->snapshot['output']      = <<< EOF
======= Task Output Body =======
> URL: $url
> Response Code: $responseCode
> Response: $responseStatus
> 
EOF;

        $this->logTask(sprintf($this->getApplication()->getLanguage()->_('PLG_WBCDORFAPP_REQUESTS_TASK_GET_REQUEST_LOG_RESPONSE'), $responseCode));

        if ($response->code !== 200) {
            return TaskStatus::KNOCKOUT;
        }

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
    protected function AppNewsArticles($responseBody) {
        $list = array();
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

            $item = json_decode($responsearticle->body);
            $list[ $itemId->id]['id'] = $itemId;
            $list[ $itemId->id]['title'] = $item->text;
            $list[ $itemId->id]['introtext'] = $item->summary;
            $list[$itemId]['fulltext'] = $item->content;
            $list[$itemId]['heading'] = $item->heading;
            // Bild auslesen
            $assetReference = $item->coverAssetReference;
            $list[$itemId]['image']['imageSrc'] = $assetReference->assetImage->assetImageUrl;
            $imageTitle = $assetReference->assetImage->text; 
            $list[$itemId]['image']['imageTitle'] = $imageTitle;
            $list[$itemId]['image']['imageQuelle'] = $assetReference->assetImage->source;
           
            $output .= $itemId . "\n\n";
            $output .= $item->text . "\n\n";
            $output .= '<------------------------------------------>' . "\n";
        }

        return $output;
    }
}
