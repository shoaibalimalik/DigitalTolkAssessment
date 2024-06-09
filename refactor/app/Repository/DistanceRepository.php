<?php

namespace DTApi\Repository;

use DTApi\Models\Distance;

/**
 * Class DistanceRepository
 * @package DTApi\Repository
 */
class DistanceRepository extends BaseRepository
{

    protected $model;

    /**
     * @param Distance $model
     */
    function __construct(Job $model)
    {
        parent::__construct($model);
    }

    /**
     * @param $user_id
     * @param $distance
     * @param $time
     * @return mixed
     */
    public function updateDistance($jobId, $distance, $time)
    {
        return Distance::where('job_id', $jobId)->update([
            'distance' => $distance,
            'time' => $time,
        ]);
    }



}