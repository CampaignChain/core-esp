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

use Monolog\Logger;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class BusinessRule
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
     * Expressions to be provided in the following forward as per below
     * example:
     *
     *
     *
     * @param array $expressions
     * @return int
     */
    public function execute(array $expressions)
    {
        $credits = 0;
        $exprLang = new ExpressionLanguage();

        foreach($expressions as $node => $clauses){
            $value = NULL;

            // Get the value of the array node.
            eval(
                'if(isset($this->data["properties"]'.$node.') && !empty($this->data["properties"]'.$node.')){'
                    .'$value = $this->data["properties"]'.$node.';'
                .'}'
            );

            if($value != NULL) {
                // Evaluate the clause and assign points accordingly.
                $credit = 0;
                foreach ($clauses as $clause) {
                    $credit = (int)$exprLang->evaluate(
                        $clause, array(
                            'value' => $value,
                            'relationships' => $this->data['relationships'],
                    ));
                    if($credit != 0) {
                        $credits = $credits + $credit;
                        break;
                    }
                }
            }
        }

        return $credits;
    }
}