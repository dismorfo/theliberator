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

        if (! config('services.elasticsearch.ssl_verify', true)) {
            $builder->setSSLVerification(false);

            // Explicitly tell the underlying HTTP client to ignore SSL issues
            $builder->setHttpClientOptions([
                'verify' => false
            ]);
        }

        return $builder->build();
    }
}
