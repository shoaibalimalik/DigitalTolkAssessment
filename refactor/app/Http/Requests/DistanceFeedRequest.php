<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DistanceFeedRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'distance' => 'nullable|string',
            'time' => 'nullable|string',
            'jobid' => 'required|integer|exists:jobs,id',
            'session_time' => 'nullable|string',
            'flagged' => 'required|boolean',
            'admincomment' => 'required_if:flagged,true|string',
            'manually_handled' => 'nullable|boolean',
            'by_admin' => 'nullable|boolean',
        ];
    }
}