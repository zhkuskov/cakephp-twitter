<?php

namespace CvoTechnologies\Twitter\Webservice;

use Cake\Core\Exception\Exception;
use Cake\Network\Exception\NotFoundException;
use Cake\Network\Http\Response;
use CvoTechnologies\Twitter\Webservice\Exception\RateLimitExceededException;
use CvoTechnologies\Twitter\Webservice\Exception\UnknownErrorException;
use Muffin\Webservice\Query;
use Muffin\Webservice\ResultSet;
use Muffin\Webservice\Webservice\Webservice;

/**
 * Class TwitterWebservice
 *
 * @method Driver\Twitter driver()
 *
 * @package CvoTechnologies\Twitter\Webservice
 */
class TwitterWebservice extends Webservice
{

    protected function _baseUrl()
    {
        return '/1.1/' . $this->endpoint();
    }

    protected function _executeCreateQuery(Query $query, array $options = [])
    {
        /* @var Response $response */
        $response = $this->driver()->client()->post($this->_baseUrl() . '/create.json', $query->set());

        if (!$response->isOk()) {
            throw new Exception($response->json['error']);
        }

        return $this->_transformResource($query->endpoint(), $response->json);
    }

    protected function _executeReadQuery(Query $query, array $options = [])
    {
        $parameters = $query->where();
        if ($query->clause('limit')) {
            $parameters['count'] = $query->clause('limit');
        }
        if ($query->clause('page')) {
            $parameters['page'] = $query->clause('page');
        }
        if ($query->clause('offset')) {
            $parameters['since_id'] = $query->clause('offset');
        }

        $index = $this->_defaultIndex();
        if (isset($query->getOptions()['index'])) {
            $index = $query->getOptions()['index'];
        }
        $url = $this->_baseUrl() . '/' . $index . '.json';

        $search = isset($query->where()['q']);
        if (!empty($query->where())) {
            $displayField = $query->endpoint()->aliasField($query->endpoint()->displayField());
            if (isset($query->where()[$displayField])) {
                $parameters['q'] = $query->where()[$displayField];
            }
            if ($search) {
                $parameters['q'] = $query->where()['q'];

                $url = $this->_baseUrl() . '/search/tweets.json';
                if ($query->endpoint()->endpoint() === 'statuses') {
                    $url = substr($url, 0, 5) . substr($url, 14);
                }

                unset($parameters['page']);
            }
        }

        if ($this->nestedResource($query->clause('where'))) {
            $url = $this->nestedResource($query->clause('where'));
        }
        if ((isset($query->where()['id'])) && (is_array($query->where()['id']))) {
            $parameters[$query->endpoint()->primaryKey()] = implode(',', $query->where()['id']);

            $url = $this->_baseUrl() . '/lookup.json';
        }

        try {
            $json = $this->_doRequest($url, $parameters);
        } catch (NotFoundException $exception) {
            return new ResultSet([], 0);
        }

        if ($json === false) {
            return false;
        }

        if ($json === null) {
            $json = [];
        }

        if ($search) {
            $resources = $this->_transformResults($query->endpoint(), $json[$query->endpoint()->endpoint()]);

            return new ResultSet($resources, $json['search_metadata']['count']);
        }

        if (key($json) !== 0) {
            $resource = $this->_transformResource($query->endpoint(), $json);

            return new ResultSet([$resource], 1);
        }

        $resources = $this->_transformResults($query->endpoint(), $json);

        $total = count($resources);
        switch ($index) {
            case 'user_timeline':
                $total = 3200;
                break;
            case 'home_timeline':
                $total = 800;
                break;
        }

        return new ResultSet($resources, $total);
    }

    protected function _executeUpdateQuery(Query $query, array $options = [])
    {
        if ((!isset($query->where()['id'])) || (is_array($query->where()['id']))) {
            return false;
        }

        $parameters = $query->set();
        $parameters[$query->endpoint()->primaryKey()] = $query->where()['id'];

        $response = $this->driver()->client()->post($this->_baseUrl() . '/update.json', $parameters);

        if (!$response->isOk()) {
            throw new Exception($response->json['errors'][0]['message']);
        }

        return $this->_transformResource($response->json, $options['resourceClass']);
    }

    protected function _executeDeleteQuery(Query $query, array $options = [])
    {
        if ((!isset($query->where()['id'])) || (is_array($query->where()['id']))) {
            return false;
        }

        $url = $this->_baseUrl() . '/destroy/' . $query->where()['id'] . '.json';

        /* @var Response $response */
        $response = $this->driver()->client()->post($url);

        if (!$response->isOk()) {
            throw new Exception($response->json['errors'][0]['message']);
        }

        return 1;
    }

    protected function _defaultIndex()
    {
        return 'list';
    }

    protected function _doRequest($url, $parameters)
    {
        /* @var Response $response */
        $response = $this->driver()->client()->get($url, $parameters);

        $this->_checkResponse($response);

        return $response->json;
    }

    protected function _checkResponse(Response $response)
    {
        if (isset($response->json['errors'][0]['message'])) {
            $error = $response->json['errors'][0]['message'];
        } else {
            $error = $response->body();
        }
        switch ($response->statusCode()) {
            case 404:
                throw new NotFoundException($error);
            case 429:
                throw new RateLimitExceededException($error, 429);
        }

        if (!$response->isOk()) {
            throw new UnknownErrorException([$response->statusCode(), $error]);
        }
    }

}
