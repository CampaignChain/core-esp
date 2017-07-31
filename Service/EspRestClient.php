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
use GuzzleHttp\Middleware;

class EspRestClient
{
    protected $client;

    protected $endpoints = array(
        'event' => '/api/private/esp/event',
    );

    public function __construct($baseUrl)
    {
        foreach($this->endpoints as $name => $uri){
            $this->endpoints[$name] = $baseUrl.$uri;
        }
        $this->client = new Client();
    }

    public function postEvent($event, $properties)
    {
        //die($this->endpoints['event']);
        $res = $this->client->request(
            'POST', $this->endpoints['event'],
            array('json' => $properties)
        );

        return json_decode($res->getBody());
    }
}