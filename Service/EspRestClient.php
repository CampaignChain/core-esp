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

namespace CampaignChain\Core\ESPBundle\Service;

use CampaignChain\Core\ESPBundle\Controller\REST\EventController;
use GuzzleHttp\Client;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;

class EspRestClient
{
    protected $client;
    protected $logger;

    protected $endpoints = array(
        'event' => '/api/v1/esp/event',
    );

    public function __construct($baseUrl, Logger $logger, $env)
    {
        if($env == 'dev'){
            $baseUrl = $baseUrl.'/app_dev.php';
        }

        foreach($this->endpoints as $name => $uri){
            $this->endpoints[$name] = $baseUrl.$uri;
        }
        $this->client = new Client();

        $this->logger = $logger;
    }

    public function postEvent($event, $properties, $relationships = array(), $workflow = null)
    {
        $this->logger->debug(
            "Attempt: Post event '".$event."' to '"
            .$this->endpoints['event']."'"
        );

        $params['json'] = array(
                'event' => $event,
                'properties' => $properties,
                'relationships' => $relationships,
            );

        if($workflow){
            $params['headers'] = array(
                EventController::WORKFLOW_HEADER => $workflow,
            );
        }

        $res = $this->client->post(
            $this->endpoints['event'], $params
        );

        return json_decode($res->getBody());
    }

    public function postEventAsync($event, $properties, $relationships = array(), $workflow)
    {
        $this->logger->debug(
            "Attempt: Post event '".$event."' asynchronously to '"
            .$this->endpoints['event']."'"
        );

        $params['json'] = array(
            'event' => $event,
            'properties' => $properties,
            'relationships' => $relationships,
        );

        if($workflow){
            $params['headers'] = array(
                EventController::WORKFLOW_HEADER => $workflow,
            );
        }

        $promise = $this->client->postAsync(
            $this->endpoints['event'], $params
        );

        return $promise->then(
            function (ResponseInterface $res) {
                $body = json_decode($res->getBody());
                if(is_array($body) && isset($body['error'])){
                    $this->logger->error($body['error']['message'], array(
                        'code' => $body['error']['code'],
                    ));
                } else {
                    $this->logger->debug('Success: Posted asynchronously');
                }
                return $body;
            },
            function (RequestException $e) {
                $this->logger->error($e->getResponse()->getBody()->getContents(), array(
                    'method' => $e->getRequest()->getMethod(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTrace(),
                ));
                throw new \Exception($e->getResponse()->getBody()->getContents());
            }
        );

        $promise->wait();
    }
}