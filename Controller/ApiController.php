<?php

namespace Syrup\ComponentBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Keboola\StorageApi\Client;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Keboola\StorageApi\Event as SapiEvent;
use Syrup\ComponentBundle\Component\Component;
use Syrup\ComponentBundle\Component\ComponentFactory;
use Syrup\ComponentBundle\Filesystem\Temp;
use Syrup\ComponentBundle\Job\Job;
use Syrup\ComponentBundle\Job\JobInterface;
use Syrup\ComponentBundle\Job\JobManager;
use Syrup\ComponentBundle\Service\Queue\QueueService;
use Syrup\ComponentBundle\Service\SharedSapi\jobEvent;
use Syrup\ComponentBundle\Service\SharedSapi\SharedSapiService;

class ApiController extends BaseController
{
	/** @var Client */
	protected $storageApi;

	protected function initStorageApi()
	{
		$this->storageApi = $this->container->get('storage_api')->getClient();
	}

	public function preExecute(Request $request)
	{
		parent::preExecute($request);

		$this->initStorageApi();
	}

	/**
	 * @param Request $request
	 * @return Response
	 */
	public function runAction(Request $request)
    {
	    $params = $this->getPostJson($request);

	    /** @var JobManager $jobManager */
	    $jobManager = $this->container->get('syrup.job_manager');

	    /** @var JobInterface $job */
	    $job = $this->initJob($this->createJob($params));

	    $jobManager->indexJob($job);

	    $this->enqueue($job->getId());

	    return $this->createJsonResponse([
		    'jobId' => $job->getId()
	    ], 201);
    }

	public function optionsAction()
	{
		$response = new Response();
		$response->headers->set('Accept', 'application/json');
		$response->headers->set('Access-Control-Allow-Origin', '*');
		$response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
		$response->headers->set('Access-Control-Allow-Headers', 'content-type, x-requested-with, x-requested-by, x-storageapi-url, x-storageapi-token, x-kbc-runid');
		$response->headers->set('Access-Control-Max-Age', '86400');
		$response->headers->set('Content-Type', 'application/json');
		$response->send();

		return $response;
	}

	/** Jobs */

	/**
	 * @param JobInterface $job
	 * @return JobInterface
	 */
	protected function initJob(JobInterface $job)
	{
		$sapiData = $this->storageApi->getLogData();
		$jobId = $this->storageApi->generateId();

		$job->setId($jobId);
		$job->setProjectId($sapiData['owner']['id']);
		$job->setToken($this->storageApi->getTokenString());
		$job->setComponent($this->componentName);
		$job->setStatus(Job::STATUS_NEW);

		return $job;
	}

	/**
	 * @param array $params
	 * @return JobInterface
	 */
	protected function createJob($params = [])
	{
		return new Job($params);
	}

	protected function enqueue($jobId, $otherData = array())
	{
		$data = array(
			'jobId'     => $jobId
		);

		if (count($otherData)) {
			$data = array_merge($data, $otherData);
		}

		/** @var QueueService $queue */
		$queue = $this->container->get('syrup.job_queue');
		$queue->enqueue($data);
	}

	protected function sendEventToSapi($type, $message, $componentName)
	{
		$sapiEvent = new SapiEvent();
		$sapiEvent->setComponent($componentName);
		$sapiEvent->setMessage($message);
		$sapiEvent->setRunId($this->container->get('syrup.monolog.json_formatter')->getRunId());
		$sapiEvent->setType($type);

		$this->storageApi->createEvent($sapiEvent);
	}

	/**
	 * @param String $actionName
	 * @param String $status
	 * @param Int $startTime
	 * @param Int $endTime
	 * @param String $params
	 */
	protected function logToSharedSapi($actionName, $status, $startTime, $endTime, $params)
	{
		$logData = $this->storageApi->getLogData();

		$ssEvent = new JobEvent(array(
			'component' => $this->component->getFullName(),
			'action'    => $actionName,
			'url'       => $this->getRequest()->getUri(),
			'projectId' => $logData['owner']['id'],
			'projectName'    => $logData['owner']['name'],
			'tokenId'   => $logData['id'],
			'tokenDesc' => $logData['description'],
			'status'    => $status,
			'startTime' => $startTime,
			'endTime'   => $endTime,
			'request'   => $params
	    ));

		try {
			$this->getSharedSapi()->log($ssEvent);
		} catch (\Exception $e) {
			$this->logger->warning("Error while logging into Shared SAPI", array(
				"message"   => $e->getMessage(),
				"exception" => $e->getTraceAsString()
			));
		}
	}

	/**
	 * @return SharedSapiService
	 */
	protected function getSharedSapi()
	{
		return $this->container->get('syrup.shared_sapi');
	}

	public function camelize($value)
	{
		if(!is_string($value)) {
			return $value;
		}

		$chunks = explode('-', $value);
		$ucfirsted = array_map(function($s) {
			return ucfirst($s);
		}, $chunks);

		return lcfirst(implode('', $ucfirsted));
	}

}
