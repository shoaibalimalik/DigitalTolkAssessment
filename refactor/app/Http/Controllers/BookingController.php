<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Http\Requests\StoreJobRequest;
use DTApi\Http\Requests\UpdateJobRequest;
use DTApi\Http\Requests\StoreJobEmailRequest;
use DTApi\Http\Requests\JobActionRequest;
use DTApi\Http\Requests\EndJobRequest;
use DTApi\Http\Requests\ReOpenJobRequest;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use DTApi\Repository\DistanceRepository;
use App\Enums\HttpStatus;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $bookingRepository;
    /**
     * @var DistanceRepository
     */
    protected $distanceRepository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     * @param DistanceRepository $distanceRepository
     */
    public function __construct(BookingRepository $bookingRepository, DistanceRepository $distanceRepository)
    {
        $this->bookingRepository = $bookingRepository;
        $this->distanceRepository = $distanceRepository;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $user_id = $request->get('user_id');
        $response = null;
        $user_type = $request->__authenticatedUser?->user_type;

        if ($user_id) { 
            $response = $this->bookingRepository->getUsersJobs($user_id);
        }
        elseif ($user_type == config('auth.adminRoleId') || $user_type == config('auth.superAdminRoleId'))
        {
            $response = $this->bookingRepository->getAll($request);
        }

        if (empty($response)) {
            return response()->json(['data' => [], 'message' => 'No jobs found for the given user'], HttpStatus::NOT_FOUND); 
        }
    
        return response()->json(['data' => $response, 'message' => 'Jobs retrieved successfully']);
    }

    /**
     * @param $id
     * @return Response
     */
    public function show($id)
    {
        $job = $this->bookingRepository->getJobById($id);

        if (!$job) {
            return response()->json(['data' => null, 'error' => 'Job not found', 'message' => null], HttpStatus::NOT_FOUND);
        }

        return response()->json(['data' => $job, 'error' => null, 'message' => 'Job retrieved successfully']);
    }

    /**
     * @param StoreJobRequest $request
     * @return Response
     */
    public function store(StoreJobRequest $request)
    {
        $validatedData = $request->validated();

        try {
            $storedData = $this->bookingRepository->store($request->__authenticatedUser, $validatedData);

            return response()->json(['data' => $storedData, 'error' => null, 'message' => 'Data stored successfully'], HttpStatus::OK);
        } catch (\Exception $e) {
            return response()->json(['data' => null, 'error' => 'Failed to store data', 'message' => $e->getMessage()], HttpStatus::INTERNAL_SERVER_ERROR);
        }

    }

    /**
     * @param $id
     * @param UpdateJobRequest $request
     * @return Response
     */
    public function update($id, UpdateJobRequest $request)
    {
        $validatedData = $request->validated();
        $cuser = $request->__authenticatedUser;

        try {
            $response = $this->bookingRepository->updateJob($id, $validatedData, $cuser);
            return response()->json(['data' => $response, 'error' => null, 'message' => 'Data updated successfully'], HttpStatus::OK);
        } catch (\Exception $e) {
            return response()->json(['data' => null, 'error' => 'Failed to update data', 'message' => $e->getMessage()], HttpStatus::INTERNAL_SERVER_ERROR);
        }    
    }

    /**
     * @param StoreJobEmailRequest $request
     * @return Response
     */
    public function immediateJobEmail(StoreJobEmailRequest $request)
    {
        $validatedData = $request->validated();

        try {
            $response = $this->bookingRepository->storeJobEmail($validatedData);
            return response()->json(['data' => $response, 'error' => null, 'message' => 'Job email stored successfully'], HttpStatus::OK);
        } catch (\Exception $e) {
            return response()->json(['data' => null, 'error' => 'Failed to store job email', 'message' => $e->getMessage()], HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function getHistory(Request $request)
    {
        $user_id = $request->get('user_id');
        $response = null;

        if ($user_id) { 
            $response = $this->bookingRepository->getUsersJobsHistory($user_id, $request);
        }

        if (empty($response)) {
            return response()->json(['data' => [], 'error' => 'No history found for the given user', 'message' => null], HttpStatus::NOT_FOUND);
        }
    
        return response()->json(['data' => $response, 'error' => null, 'message' => 'History retrieved successfully']);
    }

    /**
     * @param JobActionRequest $request
     * @return Response
     */
    public function acceptJob(JobActionRequest $request)
    {
        $validatedData = $request->validated();
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->acceptJob($validatedData, $user);

        return response()->json($response);
    }

    /**
     * @param JobActionRequest $request
     * @return Response
     */
    public function acceptJobWithId(JobActionRequest $request)
    {
        $validatedData = $request->validated();
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->acceptJobWithId($validatedData, $user);

        return response()->json($response);
    }

    /**
     * @param JobActionRequest $request
     * @return Response
     */
    public function cancelJob(JobActionRequest $request)
    {
        $validatedData = $request->validated();
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->cancelJobAjax($validatedData, $user);

        return response()->json($response);
    }

    /**
     * @param EndJobRequest $request
     * @return Response
     */
    public function endJob(EndJobRequest $request)
    {
        $validatedData = $request->validated();
        $response = $this->bookingRepository->endJob($validatedData);

        return response()->json($response);
    }

    /**
     * @param JobActionRequest $request
     * @return Response
     */
    public function customerNotCall(JobActionRequest $request)
    {
        $validatedData = $request->validated();
        $response = $this->bookingRepository->customerNotCall($validatedData);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function getPotentialJobs(Request $request)
    {
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->getPotentialJobs($user);

        return response()->json($response);
    }

    /**
     * @param DistanceFeedRequest $request
     * @return Response
     */
    public function distanceFeed(DistanceFeedRequest $request)
    {
        $validatedData = $request->validated();

        $distance = $validatedData['distance'] ?? '';
        $time = $validatedData['time'] ?? '';
        $jobid = $validatedData['jobid'];
        $session = $validatedData['session_time'] ?? '';
        $flagged = $validatedData['flagged'] ? 'yes' : 'no';
        $manually_handled = $validatedData['manually_handled'] ? 'yes' : 'no';
        $by_admin = $validatedData['by_admin'] ? 'yes' : 'no';
        $admincomment = $validatedData['admincomment'] ?? '';   

        if ($time || $distance) {
            $this->distanceRepository->updateDistance($jobid, $distance, $time);
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            $this->bookingRepository->updateJobByJobId($jobId, $adminComment, $flagged, $session, $manuallyHandled, $byAdmin);
        }

        return response()->json(['message' => 'Record updated!']);
    }

    /**
     * @param ReOpenJobRequest $request
     * @return Response
     */
    public function reopen(ReOpenJobRequest $request)
    {
        $validatedData = $request->validated();
        $response = $this->bookingRepository->reopen($validatedData);

        return response()->json($response);
    }

    /**
     * @param JobActionRequest $request
     * @return Response
     */
    public function resendNotifications(JobActionRequest $request)
    {
        $validatedData = $request->validated();
        $response = $this->bookingRepository->resendNotifications($validatedData);

        return response()->json(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $validatedData = $request->validated();

        try {
            $response = $this->bookingRepository->resendSMSNotifications($validatedData);
            return response()->json(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], HttpStatus::INTERNAL_SERVER_ERROR);
        }   
    }

}
