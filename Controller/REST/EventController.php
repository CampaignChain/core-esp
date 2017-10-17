<?php
/*
 * Copyright 2017 CampaignChain, Inc. <info@campaignchain.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CampaignChain\Core\ESPBundle\Controller\REST;

use CampaignChain\Core\ESPBundle\Service\BusinessRule;
use CampaignChain\Core\ESPBundle\Service\RestExternalConnector;
use CampaignChain\CoreBundle\Controller\REST\BaseController;
use CampaignChain\CoreBundle\Service\Elasticsearch;
use CampaignChain\CoreBundle\Util\DateTimeUtil;
use FOS\RestBundle\Controller\Annotations as REST;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use FOS\RestBundle\Request\ParamFetcher;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Elasticsearch\ClientBuilder;
use CampaignChain\Core\ESPBundle\Validator\EventValidator;

/**
 * @REST\NamePrefix("campaignchain_core_esp_rest_")
 *
 * Class EventController
 * @package CampaignChain\Core\ESPBundle\Controller\REST
 */
class EventController extends BaseController
{
    protected $package;
    protected $event;
    protected $data;

    protected function prepareData($data)
    {
        /*
         * Check if REST payload data contains all required data.
         */
        if(!isset($data['event']) || empty($data['event'])){
            $this->throwException('Event name not defined', $data);
        }

        $this->logDebug('Event: '.$data['event']);

        if(!isset($data['properties'])){
            $this->throwException('Properties not defined', $data);
        }

        $properties = $data['properties'];
        if(!is_array($properties) || !count($properties)){
            $this->throwException('Properties data is empty', $data);
        }

        /*
         * Parse event name.
         */
        EventValidator::isValidUri($data['event']);
        $eventParts = explode('/', $data['event']);
        if(count($eventParts) == 2 || count($eventParts) > 3) {
            // The package name is not correct.
            $errMsg = 'The event URI is not correct. '
                .'It should consist of either just the event name or '
                .'the event name prefixed with a CampaignChain package name.';
            $this->throwException($errMsg, $data);
        }
        if(count($eventParts) > 1) {
            //
            $this->package = $eventParts[0] . '/' . $eventParts[1];
            $this->event = $eventParts[2];
        }

        unset($data['event']);
        $this->data = $data;
    }

    /**
     * Send event data to CampaignChain to track any actions a user performs,
     * such as data about a user who registered in your online shop.
     *
     * The general structure of the input is:
     *
     * - event: The provided value is supposed to start with the composer
     *          package name of the CampaignChain module which defines and
     *          processes the data (e.g. "campaignchain/operation-facebook").
     *          The event name is then being appended (e.g. "/Likes"), which
     *          must start with a capital letter and resemble the name of the
     *          protobuf message name.
     *
     * - properties: Holds the data points related to the tracked event.
     *
     * Example Request
     * ===============
     *
     *      POST /api/v1/esp/event
     *
     * Example Input
     * =============
     *
    {
    "event": "campaignchain/operation-facebook/Likes",
    "properties": {
    "data": [
    {
    "id": "100000000000000000",
    "name": "John Doe"
    }
    ],
    "paging": {
    "cursors": {
    "before": "MTAyMDM1MzIxNjM3MjcwNjQZD",
    "after": "MTAyMDM1MzIxNjM3MjcwNjQZD"
    }
    },
    "summary": {
    "total_count": 42
    }
    }
    }
     *
     * Example Response
     * ================
     * See:
     *
     *      GET /api/v1/esp/event/{id}
     *
     * @ApiDoc(
     *  section="Packages: Event Stream Processing (ESP)"
     * )
     *
     * @REST\Post("/event")
     * @ParamConverter("data", class="array", converter="fos_rest.request_body")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function postEventAction(Request $request, $data)
    {
        $this->logDebug('[START][ESP EVENT]');
        try {
            $this->prepareData($data);

            /*
             * Load the Protobuf class files
             */
            $namespace = '\\'.$this->event.'\\';
            $generatedProtoPath =
                $this->getParameter('campaignchain_protobuf.php_out')
                .DIRECTORY_SEPARATOR.$this->package;

            $protoMetadataPath = $generatedProtoPath.DIRECTORY_SEPARATOR.'GPBMetadata';
            $protoMetadataFile = $protoMetadataPath.DIRECTORY_SEPARATOR.$this->event.'.php';

            if(!file_exists($protoMetadataFile)){
                $this->throwException(
                    'Proto for event "'.$this->event.'" does not exist in package "'.$this->package.'"',
                    $data
                );
            }

            $finder = new Finder();
            $finder->files()->in($generatedProtoPath)->name('*.php');

            foreach ($finder as $file) {
                require $file->getRealPath();
            }

            /*
             * Instantiate the Proto class.
             */
            $protoClass = $namespace.$this->event;
            /** @var \Likes\Likes $protoObj */
            $protoObj = new $protoClass();

            // Check if data is valid.
            $protoObj->mergeFromJsonString(json_encode($this->data['properties']));

            // Filter data through Proto to omit data points not defined there.
            $this->data['properties'] = json_decode($protoObj->serializeToJsonString(), true);

            if(!is_array($this->data['properties']) || !count($this->data['properties'])){
                $this->throwException("None of the properties match the .proto definition for event '".$this->event."'");
            }

            /*
             * Set or parse common fields.
             */
            /** @var DateTimeUtil $dateTimeUtil */
            $dateTimeUtil = $this->get('campaignchain.core.util.datetime');
            $now = $dateTimeUtil->getNow();
            $this->data['receivedAt'] = $now->format(\DateTime::ISO8601);
            if(!isset($this->data['timestamp'])){
                $this->data['timestamp'] = $this->data['receivedAt'];
            }

            /*
             * Set context variables.
             */
            if(!empty($request->getClientIp())) {
                $this->data['context']['ip'] = $request->getClientIp();
            }
            if(!empty($request->getLocale())) {
                $this->data['context']['locale'] = $request->getLocale();
            }

            /*
             * Handle relationships.
             *
             * Relationships define IDs and other parameters which are relevant
             * for finding related data sets or for applying business rules.
             */
            if(!isset($this->data['relationships'])){
                $this->data['relationships'] = array();
            }

            /*
             * Get the package's ESP configuration parameters.
             */
            try {
                // Handle with grace if no managers have been defined at all.
                $confParamsAll = $this->getParameter('campaignchain.core.esp');
            } catch(\Exception $e) {}

            /*
             * Process the data.
             */
            if(
                isset($confParamsAll) && is_array($confParamsAll)
                && count($confParamsAll) && isset($confParamsAll[$this->package])
            ) {
                $this->logDebug('Package "'.$this->package.'" has configuration parameters.');
                $confParams = $confParamsAll[$this->package];

                /*
                 * Run the rule groups defined for this event.
                 */
                if (
                    isset($confParams['events'])
                    && isset($confParams['events'][$this->event])
                    && isset($confParams['events'][$this->event]['rules'])
                ) {
                    $this->logDebug('Executing rule groups for package "'.$this->package.'".');

                    $ruleGroups = $confParams['events'][$this->event]['rules'];

                    foreach($ruleGroups as $ruleGroupName => $ruleGroup) {
                        /** @var BusinessRule $rulesService */
                        $rulesService = $this->get(
                            $ruleGroup['rule']['service']
                        );
                        $rulesService->setData($this->data);

                        $this->logDebug('Executing rule group "'.$ruleGroupName.'"');

                        try{
                            $ruleResult = $rulesService->execute($ruleGroup['criteria']);
                            $this->data['rules']['results'][$ruleGroupName] = $ruleResult;
                            $this->logDebug($ruleGroupName.' = '.json_encode($ruleResult));
                        } catch(\Exception $e){
                            $this->logError($e->getMessage(), array(
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'trace' => $e->getTrace(),
                            ));
                        }
                    }
                }

                /*
                 * Call a bundle's ESP manager.
                 */
                if (isset($confParams['manager'])) {
                    $this->logDebug('Package "'.$this->package.'" has an ESP Manager.');
                    $espManager = $this->get($confParams['manager']);
                    $this->data = $espManager->detachEvent($this->event, $this->data);

                    // Check if data returned by ESP Manager is valid.
                    $protoObj->mergeFromJsonString(json_encode($this->data['properties']));
                }

                /*
                 * Run the tasks defined for a rule group.
                 */
                if (
                    isset($confParams['events'])
                    && isset($confParams['events'][$this->event])
                    && isset($confParams['events'][$this->event]['rules'])
                ) {
                    $this->logDebug('Executing tasks of rule groups for package "'.$this->package.'".');
                    $exprLang = new ExpressionLanguage();

                    $ruleGroups = $confParams['events'][$this->event]['rules'];

                    $tasksResult = array();

                    foreach($ruleGroups as $ruleGroupName => $ruleGroup) {
                        $this->logDebug('Executing rule group "'.$ruleGroupName.'"');
                        if(isset($ruleGroup['tasks']) && is_array($ruleGroup['tasks'])) {
                            try {
                                foreach ($ruleGroup['tasks'] as $taskName => $taskConfig) {
                                    $this->logDebug('Executing task "' . $taskName . '"');
                                    $service = $this->get($taskConfig['service']);
                                    $this->logDebug('Results of previous tasks: '.json_encode($tasksResult));
                                    $tasksResult[$taskName] = $exprLang->evaluate(
                                        $taskConfig['method'], array(
                                        'result' => $this->data['rules']['results'][$ruleGroupName],
                                        'service' => $service,
                                        'properties' => $this->data['properties'],
                                        'relationships' => $this->data['relationships'],
                                        'tasks' => $tasksResult,
                                    ));
                                }
                            } catch (\Exception $e) {
                                $this->logError($e->getMessage(), array(
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'trace' => $e->getTrace(),
                                ));
                            }
                        }
                    }
                }
            }

            /*
             * Put data into Elasticsearch
             */
            /** @var Elasticsearch $esService */
            $esService = $this->get('campaignchain.core.service.elasticsearch');
            $esClient = $esService->getClient();

            $esIndex =
                $this->getParameter('elasticsearch_index')
                .'.esp.'
                .str_replace('/', '.', $this->package);

            $params = [
                'index' => $esIndex,
                'type'  => $this->event,
                'body'  => $this->data,
            ];
            $response = $esClient->index($params);

            $this->logDebug('[END][ESP EVENT]');

//            $response = $this->forward(
//                $getActivityControllerMethod,
//                array(
//                    'id' => $activity->getId()
//                )
//            );
//            return $response->setStatusCode(Response::HTTP_CREATED);
            return $this->response($response);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode());
        }
    }
}