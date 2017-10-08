<?php
/*
 * Copyright 2016 CampaignChain, Inc. <info@campaignchain.com>
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

namespace Application\Migrations;

use CampaignChain\CoreBundle\Service\Elasticsearch;
use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Platformsh\Channel\AccountsBundle\Service\SalesforceClient;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use CampaignChain\CoreBundle\Util\VariableUtil;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
class Version20171008131313 extends AbstractMigration implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Syncing all visitor Lead fields with Clearbit data.
     *
     * @param Schema $schema
     */
    public function preUp(Schema $schema)
    {
        /** @var Elasticsearch $esService */
        $esService = $this->container->get('campaignchain.core.service.elasticsearch');
        $esClient = $esService->getClient();

        // Get all indices
        $params = [
            'index' => $this->container->getParameter('elasticsearch_index').'.esp.*'
        ];
        $indices = $esClient->cat()->indices($params);

        // For each index, rename the fields
        if(count($indices)){
            // Ingest the rename pipelines
            $params = [
                'id' => 'rename_context_timestamps',
                'body' => [
                    'description' => 'Rename properties.timestamp/received_at to context.timestamp/receivedAt',
                    'processors' => [
                        0 => [
                            'rename' => [
                                'field' => 'properties.timestamp',
                                'target_field' => 'context.timestamp'
                            ],
                        ],
                        1 => [
                            'rename' => [
                                'field' => 'properties.received_at',
                                'target_field' => 'context.receivedAt'
                            ],
                        ],
                    ],
                ],
            ];
            $esClient->ingest()->putPipeline($params);

            foreach($indices as $index){
                echo $esIndexOld = $index['index'];
                echo "\n";
                $plorp = substr(strrchr($esIndexOld,'.'), 1);
                $esIndexWithoutTimestamp = substr($esIndexOld, 0, - (strlen($plorp) + 1));
                echo $esIndexNew = $esIndexWithoutTimestamp.'.'.time();
                echo "\n";
                echo $esIndexAlias = str_replace(
                    $this->container->getParameter('elasticsearch_index').'_',
                    $this->container->getParameter('elasticsearch_index'),
                    $esIndexWithoutTimestamp
                );
                echo "\n";

                // Copy the existing index
                $params = [
                    'body' => [
                        'source' => [
                            'index' => $esIndexOld,
                        ],
                        'dest' => [
                            'index' => $esIndexNew,
                            'pipeline' => 'rename_context_timestamps'
                        ],
                    ],
                ];
                echo 'Renaming context timestamps in ' . $esIndexOld . ' for ' . $esIndexNew;
                echo "\n";
                $esClient->reindex($params);

                // Delete the existing index
                echo 'Deleting index ' . $esIndexOld;
                echo "\n";
                $esClient->indices()->delete(array(
                    'index' => $esIndexOld,
                ));

                // Create the alias
                $params = [
                    'index' => $esIndexNew,
                    'name' => $esIndexAlias,
                ];
                echo 'Creating alias from index ' . $esIndexNew . ' with name ' . $esIndexAlias;
                echo "\n";
                $esClient->indices()->putAlias($params);
            }
        }

        // Set the index alias
    }

    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
    }
}
