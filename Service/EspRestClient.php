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

use GuzzleHttp\Client;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;

class EspRestClient
{
    protected $client;
    protected $logger;

    protected $endpoints = array(
        'event' => '/api/private/esp/event',
    );

    public function __construct($baseUrl, Logger $logger)
    {
        foreach($this->endpoints as $name => $uri){
            $this->endpoints[$name] = $baseUrl.$uri;
        }
        $this->client = new Client();

        $this->logger = $logger;
    }

    public function postEvent($event, $properties)
    {
        $this->logger->debug(
            "Attempt: Post event '".$event."' asynchronously to '"
            .$this->endpoints['event']."'"
        );

        $promise = $this->client->postAsync(
            $this->endpoints['event'],
            array('json' =>
                array(
                    'event' => $event,
                    'properties' => $properties,
                )
            )
        );

        $promise->then(
            function (ResponseInterface $res) {
                $this->logger->debug('Success: Posted asynchronously');
                return json_decode($res->getBody());

            },
            function (RequestException $e) {
                $this->logger->error($e->getMessage(), array(
                    'method' => $e->getRequest()->getMethod(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTrace(),
                ));
                throw new \Exception($e->getMessage());
            }
        );

        $promise->wait();
    }
}