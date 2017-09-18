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

use CampaignChain\Core\ESPBundle\Validator\EventValidator;
use Monolog\Logger;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class DataMapper
{
    protected $logger;
    protected $data;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Inject the data as defined by the respective Google Protocol Buffer
     * .proto.
     *
     * @param array $data
     */
    public function setData(array $data)
    {
        $this->data = $data;
    }

    /**
     * Expressions to be provided.
     *
     * @param array $criteria
     * @return array
     */
    public function execute(array $criteria)
    {
        $result = array();

        foreach($criteria as $source => $targets) {
            EventValidator::isValidPropertyPath($source);

            foreach($targets as $target) {
                EventValidator::isValidPropertyPath($target);

                // Get the value of the array node.
                eval(
                    'if(isset($this->data["properties"]' . $source . ') && strlen($this->data["properties"]' . $source . ') != 0){'
                    . '$result'.$target.' = $this->data["properties"]' . $source . ';'
                    . '}'
                );
            }
        }

        return $result;
    }
}