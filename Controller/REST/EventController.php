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

use CampaignChain\CoreBundle\Controller\REST\BaseController;
use FOS\RestBundle\Controller\Annotations as REST;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use FOS\RestBundle\Request\ParamFetcher;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

/**
 * @REST\NamePrefix("campaignchain_core_esp_rest_")
 *
 * Class EventController
 * @package CampaignChain\Core\ESPBundle\Controller\REST
 */
class EventController extends BaseController
{
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
        try {
            if(!isset($data['event']) || empty($data['event'])){
                throw new \Exception('Event name not defined');
            }

            if(!isset($data['properties'])){
                throw new \Exception('Properties not defined');
            }

            $properties = $data['properties'];
            if(!is_array($properties) || !count($properties)){
                throw new \Exception('Properties data is empty');
            }

            $eventURI = $data['event'];
            $eventParts = explode('/', $eventURI);
            if(count($eventParts) == 2 || count($eventParts) > 3) {
                // The package name is not correct.
                throw new \Exception(
                    'The event URI is not correct. '
                    .'It should consist of either just the event name or '
                    .'the event name prefixed with a CampaignChain package name.'
                );
            }
            if(count($eventParts) > 1) {
                //
                $package = $eventParts[0] . '/' . $eventParts[1];
                $event = $eventParts[2];
            }

            /*
             * Load the Protobuf class files
             */
            $namespace = '\\'.$event.'\\';
            $generatedProtoPath =
                $this->getParameter('campaignchain_protobuf.php_out')
                .DIRECTORY_SEPARATOR.$package;
            $protoPackagePath = $generatedProtoPath.DIRECTORY_SEPARATOR.$event;

            require $generatedProtoPath.DIRECTORY_SEPARATOR.'GPBMetadata'.DIRECTORY_SEPARATOR.$event.'.php';

            $protoFiles = array_diff(scandir($protoPackagePath), array('..', '.'));
            foreach($protoFiles as $protoFile){
                require $protoPackagePath.DIRECTORY_SEPARATOR.$protoFile;
            }

            /*
             * Instantiate the Proto class.
             */
            $protoClass = $namespace.$event;
            /** @var \Likes\Likes $protoObj */
            $protoObj = new $protoClass();

            $protoObj->mergeFromJsonString(json_encode($data['properties']));

            $data['properties'] = json_decode($protoObj->serializeToJsonString(), true);

//            $response = $this->forward(
//                $getActivityControllerMethod,
//                array(
//                    'id' => $activity->getId()
//                )
//            );
//            return $response->setStatusCode(Response::HTTP_CREATED);
            return $this->response($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode());
        }
    }
}