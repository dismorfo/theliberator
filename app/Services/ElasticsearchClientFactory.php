<?php

namespace App\Services;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

class ElasticsearchClientFactory
{
    public function make(): Client
    {
        $builder = ClientBuilder::create()
            ->setHosts([config('services.elasticsearch.host', 'http://localhost:9200')]);

        $username = config('services.elasticsearch.username');
        $password = config('services.elasticsearch.password');

        if (is_string($username) && $username !== '' && is_string($password) && $password !== '') {
            $builder->setBasicAuthentication($username, $password);
        }

        return $builder->build();
    }
}
