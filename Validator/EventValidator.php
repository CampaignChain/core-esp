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

namespace CampaignChain\Core\ESPBundle\Validator;

use CampaignChain\Core\ESPBundle\Service\BusinessRule;
use CampaignChain\Core\ESPBundle\Service\RestExternalConnector;
use CampaignChain\CoreBundle\Controller\REST\BaseController;
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

class EventValidator
{
    public static function isValidUri($value)
    {
        // allow
        // vendor/bundle-name/event
        foreach (explode(',', $value) as $c) {
            $c = trim(strtr($c, '/', '\\'));
            if (!preg_match('/^(?:[a-zA-Z][a-zA-Z0-9_-]*\\\?)+((?:[a-zA-Z][a-zA-Z0-9_-]*\\\?))+([a-zA-Z][a-zA-Z0-9_]*)((?:[a-zA-Z][a-zA-Z0-9_]*?))+$/', $c)) {
                throw new \InvalidArgumentException('Not a valid event URI. It should be in the format "[vendor-name]/[bundle-name]/[event-name]".');
            }
        }
        return true;
    }

    public static function isValidPropertyPath($value)
    {
        // allow
        // my.property.path
        if (!preg_match('/\[\'([a-zA-Z][a-zA-Z0-9_-]*?)\'\]|\["([a-zA-Z][a-zA-Z0-9_-]*?)"\]/', $value)) {
            throw new \InvalidArgumentException("Not a valid property path. It should be for example: ['my']['property']['path']");
        }
        return true;
    }
}