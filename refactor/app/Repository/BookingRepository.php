<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }


    /**
     * @param $id
     * @return Job|null
     */
    public function getJobById($id)
    {
        return Job::with('translatorJobRel.user')->find($id);
    }

    /**
     * @param $data
     * @return boolean
     */
    public function resendNotifications($data)
    {
        $jobId = $data['job_id'];
        $job = Job::find($jobId);
        $job_data = $this->jobToData($job);
        $this->sendNotificationTranslator($job, $job_data, '*');
        return true;
    }

    /**
     * @param $data
     * @return boolean
     */
    public function resendSMSNotifications($data)
    {
        $jobId = $data['job_id'];
        $job = Job::find($jobId);
        
        try {
            $this->sendSMSNotificationToTranslator($job);
            return true;
        } catch ( \Exception $e ) {
            throw new \Exception('Failed to resend SMS notification: ' . $e->getMessage());
        }     
    }

    /**
     * @param $jobId
     * @param $adminComment
     * @param $flagged
     * @param $session
     * @param $manuallyHandled
     * @param $byAdmin
     * @return mixed
     */
    public function updateJobByJobId($jobId, $adminComment, $flagged, $session, $manuallyHandled, $byAdmin)
    {
        return Job::where('id', $jobId)->update([
            'admin_comments' => $adminComment,
            'flagged' => $flagged,
            'session_time' => $session,
            'manually_handled' => $manuallyHandled,
            'by_admin' => $byAdmin,
        ]);
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {

        $user = User::find($user_id);
        if (!$user) {
            return ['emergencyJobs' => [], 'normalJobs' => [], 'user' => null, 'userType' => ''];
        }

        $jobs = [];
        $emergencyJobs = [];
        $normalJobs = [];
        $userType = '';

        if ($user->is('customer')) {
            $userType = 'customer';
            $jobsQuery = $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc');
            $jobs = $jobsQuery->get();
        } elseif ($user->is('translator')) {
            $userType = 'translator';
            $jobsQuery = Job::getTranslatorJobs($user->id, 'new');
            $jobs = $jobsQuery->pluck('jobs')->flatten();  
        }

        $groupedJobs = collect($jobs)->groupBy('immediate');
        $emergencyJobs = $groupedJobs->get('yes', []);
        $normalJobs = $groupedJobs->get('no', []);

        $normalJobs = collect($normalJobs)->each(function ($job) use ($user_id) {
            $job['usercheck'] = Job::checkParticularJob($user_id, $job);
        })->sortBy('due')->all();

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'user' => $user,
            'userType' => $userType
        ];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->input('page', 1);
        $cuser = User::find($user_id);
        $usertype = '';
    
        if (!$cuser) {
            return [];
        }

        $jobs = [];
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser->is('customer')) {
            $usertype = 'customer';
            $jobsQuery = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc');
    
            $jobs = $this->paginateJobs($jobsQuery, $page);
        } elseif ($cuser->is('translator')) {
            $usertype = 'translator';
            $jobsQuery = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
            $totalJobs = $jobsQuery->total();
            $numPages = ceil($totalJobs / 15);
    
            $jobs = $this->paginateJobs($jobsQuery, $page);
            $normalJobs = $jobs->items();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'jobs' => $jobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
            'numpages' => $numPages ?? 0,
            'pagenum' => $page
        ];
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;

        if ($user->user_type == config('auth.customerRoleId')) { 

            $response = [
                'status' => 'success',
                'id' => $job->id,
                'job_for' => [],
                'customer_physical_type' => null
            ];

            $cuser = $user;

            $data['customer_phone_type'] ??= 'no';
            $data['customer_physical_type'] ??= 'no';

            $data['customer_physical_type'] = $response['customer_physical_type'] = $data['customer_physical_type'] ?? 'no';

            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    return [
                        'status' => 'fail',
                        'message' => "Can't create booking in past",
                    ];
                }
            }

            $data['gender'] = in_array('male', $data['job_for']) ? 'male' : (in_array('female', $data['job_for']) ? 'female' : null);

            $certifiedMatches = array_intersect($data['job_for'], ['certified', 'certified_in_law', 'certified_in_health']);
            $data['certified'] = null;
            
            if (in_array('normal', $data['job_for']) && !empty($certifiedMatches)) {
                $data['certified'] = 'both';
            } elseif (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            } elseif (in_array('certified', $certifiedMatches)) {
                $data['certified'] = 'yes';
            } elseif (in_array('certified_in_law', $certifiedMatches)) {
                $data['certified'] = 'law';
            } elseif (in_array('certified_in_health', $certifiedMatches)) {
                $data['certified'] = 'health';
            }

            $data['job_type'] = match ($consumer_type) {
                'rwsconsumer' => 'rws',
                'ngo' => 'unpaid',
                'paid' => 'paid',
                default => null,
            };

            $data['b_created_at'] = date('Y-m-d H:i:s');
            $data['will_expire_at'] ??= isset($due) ? TeHelper::willExpireAt($due, $data['b_created_at']) : null;
            $data['by_admin'] ??= 'no';

            try {
                $job = $cuser->jobs()->create($data);
                return $job;
            } catch (\Exception $e) {
                throw new \Exception('Failed to send Email: ' . $e->getMessage());
            }
        } else {
            $response = [
                'status' => 'fail',
                'message' => "Translator can not create booking"
            ];
        }

        return $response;        

    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $job = Job::findOrFail($data['user_email_job_id']);
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';
        $user = $job->user()->first();
        
        if (isset($data['address'])) {
            $userMeta = $user->userMeta;
            $job->address = $data['address'] ?: $userMeta?->address;
            $job->instructions = $data['instructions'] ?: $userMeta?->instructions;
            $job->town = $data['town'] ?: $userMeta?->city;
        }
    
        $job->save();
    
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
    
        try {
            $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);
            $data = $this->jobToData($job);
            Event::fire(new JobWasCreated($job, $data, '*'));
            return [
                'type' => $data['user_type'],
                'job' => $job,
                'status' => 'success'
            ];
        } catch (\Exception $e) {
            throw new \Exception('Failed to send Email: ' . $e->getMessage());
        }
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta?->customer_type,
        ];

        [$due_date, $due_time] = explode(" ", $job->due);
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $job_for_mapping = [
            'male' => 'Man',
            'female' => 'Kvinna',
            'both' => ['Godkänd tolk', 'Auktoriserad'],
            'yes' => 'Auktoriserad',
            'n_health' => 'Sjukvårdstolk',
            'law' => 'Rätttstolk',
            'n_law' => 'Rätttstolk',
        ];

        if ($job->gender && isset($job_for_mapping[$job->gender])) {
            $data['job_for'][] = $job_for_mapping[$job->gender];
        }
        if ($job->certified && isset($job_for_mapping[$job->certified])) {
            if (is_array($job_for_mapping[$job->certified])) {
                $data['job_for'] = array_merge($data['job_for'] ?? [], $job_for_mapping[$job->certified]);
            } else {
                $data['job_for'][] = $job_for_mapping[$job->certified];
            }
        }

        return $data;
    }

    /**
     * @param $post_data
     * @return mixed
     */
    public function endJob($post_data)
    {
        $completeddate = now();
        $jobid = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->find($jobid);

        if($job->status != 'started')
            return ['status' => 'success'];

        // Calculate session time
        $start = date_create($job->due);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $sessionTime = $diff->format('%h:%i:%s');

        // Update job details
        $job->end_at = $completedDate;
        $job->status = 'completed';
        $job->session_time = $sessionTime;
        $job->save();

        // Prepare email data
        $user = $job->user;
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionTimeFormatted = date('H', strtotime($sessionTime)) . ' tim ' . date('i', strtotime($sessionTime)) . ' min';

        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTimeFormatted,
            'for_text'     => ($post_data['userid'] == $job->user_id) ? 'faktura' : 'lön'
        ];

        try {

            $mailer = new AppMailer();
            $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    
            // Find and update translator job relation
            $translatorJob = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
            $translatorJob->completed_at = $completedDate;
            $translatorJob->completed_by = $post_data['userid'];
            $translatorJob->save();
    
            $translator = $translatorJob->user;
            $email = $translator->email;
            $name = $translator->name;
            $data['for_text'] = 'lön';
            $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
        
            // Dispatch event
            $userId = ($post_data['userid'] == $job->user_id) ? $translator->id : $job->user_id;
            Event::fire(new SessionEnded($job, $userId));

            $response['status'] = 'success';
            return $response;
            
        } catch (\Exception $e) {
            throw new \Exception('Failed to send Email: ' . $e->getMessage());
        }
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;

        $job_type = match($translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
             default => 'unpaid',
        };

        $user_languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $user_languages, $user_meta->gender, $user_meta->translator_level);

        $job_ids = $job_ids->filter(function ($job) use ($user_id) {
            $job_detail = Job::find($job->id);
            $is_physical_and_out_of_town = ($job_detail->customer_phone_type == 'no' || $job_detail->customer_phone_type == '')
                && $job_detail->customer_physical_type == 'yes'
                && !Job::checkTowns($job_detail->user_id, $user_id);
            return !$is_physical_and_out_of_town;
        });

        return TeHelper::convertJobIdsInObjs($job_ids->values()->all());
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::where('user_type', '2')
                    ->where('status', '1')
                    ->where('id', '<>', $exclude_user_id)
                    ->get();

        $translator_array = [];
        $delpay_translator_array = [];

        foreach ($users as $oneUser) {
            if (!$this->isNeedToSendPush($oneUser->id)) continue;
            if ($data['immediate'] == 'yes' && TeHelper::getUsermeta($oneUser->id, 'not_get_emergency') == 'yes') continue;

            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);
            foreach ($jobs as $oneJob) {
                if ($job->id == $oneJob->id && Job::assignedToPaticularTranslator($oneUser->id, $oneJob->id) == 'SpecificJob') {
                    if (Job::checkParticularJob($oneUser->id, $oneJob) != 'userCanNotAcceptJob') {
                        if ($this->isNeedToDelayPush($oneUser->id)) {
                            $delpay_translator_array[] = $oneUser;
                        } else {
                            $translator_array[] = $oneUser;
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = $data['immediate'] == 'no' ? 
                    'Ny bokning för ' . $data['language'] . ' tolk ' . $data['duration'] . ' min ' . $data['due'] :
                    'Ny akutbokning för ' . $data['language'] . ' tolk ' . $data['duration'] . ' min';
        $msg_text = ["en" => $msg_contents];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?: $jobPosterMeta->city;

        // Determine the job type and select the appropriate message template
        $messageTemplateKey = $job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no' ? 'sms.physical_job' : 'sms.phone_job';

        $message = trans($messageTemplateKey, [
            'date' => $date,
            'time' => $time,
            'duration' => $duration,
            'jobId' => $jobId,
            'town' => $city
        ]);

        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(config('app.smsNumber'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        // Set Onesignal configuration based on environment
        $envConfig = config('app.appEnv') == 'prod' ? 'prod' : 'dev';
        $onesignalAppID = config("app.{$envConfig}OnesignalAppID");
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config("app.{$envConfig}OnesignalApiKey"));

        $user_tags = json_decode($this->getUserTagsStringFromArray($users));

        $ios_sound = $android_sound = 'default';
        if ($data['notification_type'] == 'suitable_job') {
            $sound_type = $data['immediate'] == 'no' ? 'normal_booking' : 'emergency_booking';
            $android_sound = $sound_type;
            $ios_sound = "$sound_type.mp3";
        }

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => $user_tags,
            'data'           => array_merge($data, ['job_id' => $job_id]),
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];

        if ($is_need_delay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);

        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $job_type = $job->job_type;
        $translator_type = match ($job_type) {
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
            default => null,
        };

        $translator_level = match ($job->certified) {
            'yes', 'both' => [
                'Certified', 
                'Certified with specialisation in law', 
                'Certified with specialisation in health care'
            ],
            'law', 'n_law' => ['Certified with specialisation in law'],
            'health', 'n_health' => ['Certified with specialisation in health care'],
            'normal', 'both' => [
                'Layman', 
                'Read Translation courses'
            ],
            null => [
                'Certified', 
                'Certified with specialisation in law', 
                'Certified with specialisation in health care', 
                'Layman', 
                'Read Translation courses'
            ],
            default => [],
        };

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();

        return User::getPotentialUsers(
            $translator_type, 
            $job->from_language_id, 
            $job->gender, 
            $translator_level, 
            $blacklist
        );
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        // Retrieve the current translator
        $current_translator = $job->translatorJobRel
                ->where('cancel_at', null)
                ->first() ?? $job->translatorJobRel
                ->where('completed_at', '!=', null)
                ->first();

        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) 
              $log_data[] = $changeTranslator['log_data'];

        // change due
        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        // change language
        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        // change status
        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];
        $job->save();

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        if ($job->due > Carbon::now()) {
            if ($changeDue['dateChanged']) 
                $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) 
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) 
                $this->sendChangedLangNotification($job, $old_lang);   
        }

        return ['Updated'];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $new_status = $data['status'];
        
        if ($old_status === $new_status) {
            return ['statusChanged' => false];
        }
    
        $statusChanged = false;
        $methodName = 'change' . ucfirst($old_status) . 'Status';
        if (method_exists($this, $methodName)) {
            if ($old_status === 'timedout' || $old_status === 'pending') {
                $statusChanged = $this->$methodName($job, $data, $changedTranslator);
            } else {
                $statusChanged = $this->$methodName($job, $data);
            }
        }

        if ($statusChanged) {
            $log_data = [
                'old_status' => $old_status,
                'new_status' => $new_status
            ];

            return ['statusChanged' => true, 'log_data' => $log_data];
        }
    
        return ['statusChanged' => false];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $new_status = $data['status'];
        
        if ($old_status === $new_status) {
            return false;
        }

        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $job->created_at = now();
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;

        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();

        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') { 
            $user = $job->user;
            if (empty($data['sesion_time']))
                return false;

            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            
            $job->end_at = now();
            $job->session_time = $interval;
            $session_time = "{$diff[0]} tim {$diff[1]} min";

            $email = $job->user_email ?? $user->email;
            $name = $user->name;

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            try {
                $subject = "Information om avslutad tolkning för bokningsnummer #{$job->id}";
                $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    
                $translatorJobRel = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
    
                if ($translatorJobRel) {
                    $email = $translatorJobRel->user->email;
                    $name = $translatorJobRel->user->name;
    
                    $subject = "Information om avslutad tolkning för bokningsnummer #{$job->id}";
                    $dataEmail['for_text'] = 'lön';
    
                    $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
                }
            } catch ( \Exception $e ) {
                return false;
            }  
        }

        $job->save();

        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        $job->save();

        if ($data['status'] == 'assigned' && $changedTranslator) {
            try {
                $job_data = $this->jobToData($job);

                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
    
                $translator = Job::getJobsAssignedTranslatorDetail($job);
                $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
    
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    
                $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
                $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

                return true;
            } catch ( \Exception $e ) {
                return false;
            }    
        } else {
            try {
                $subject = 'Avbokning av bokningsnr: #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                return true;
            } catch ( \Exception $e ) {
                return false;
            }    
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (!in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            return false;
        }

        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        $job->save();

        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
            $user = $job->user()->first();
            $email = $job->user_email ?? $user->email;
            $name = $user->name;
    
            $dataEmail = [
                'user' => $user,
                'job'  => $job
            ];

            try {
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        
                $translator = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
                $email = $translator->user->email;
                $name = $translator->user->name;
        
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);

                return true;
            } catch ( \Exception $e ) {
                return false;
            }
        }
    
        return true;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];
    
        $newTranslatorId = $data['translator'] ?? 0;
        $translatorEmail = $data['translator_email'] ?? '';

        if ($translatorEmail !== '') {
            $newTranslatorId = User::where('email', $translatorEmail)->first()->id ?? 0;
        }

        if (!is_null($current_translator) || $newTranslatorId !== 0 || $translatorEmail !== '') {
            if (!is_null($current_translator) && ($current_translator->user_id != $newTranslatorId) && $newTranslatorId !== 0) {
                $new_translator = $current_translator->replicate(['id', 'created_at', 'updated_at']);
                $new_translator->user_id = $newTranslatorId;
                $new_translator->save();
    
                $current_translator->cancel_at = now();
                $current_translator->save();
    
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && $newTranslatorId !== 0) {
                $new_translator = Translator::create(['user_id' => $newTranslatorId, 'job_id' => $job->id]);
    
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
        }

        return [
                'translatorChanged' => $translatorChanged, 
                'new_translator' => $new_translator ?? null, 
                'log_data' => $log_data
            ];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     * @return boolean
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';

        $data = [
            'user' => $user,
            'job'  => $job
        ];

        try {
            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
            if ($current_translator) {
                $user = $current_translator->user;
                $name = $user->name;
                $email = $user->email;
                $data['user'] = $user;
    
                $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
            }
    
            $user = $new_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;
    
            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

            return true;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    /**
     * @param $job
     * @param $old_time
     * @return boolean
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';

        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];

        try {
            $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $data = [
                'user'     => $translator,
                'job'      => $job,
                'old_time' => $old_time
            ];
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

            return true;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    /**
     * @param $job
     * @param $old_lang
     * @return boolean
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];

        try {
            $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

            return true;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     * @return boolean
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::with('user.userMeta')->findOrFail($job_id);
        if(!$job) {
            return false;
        }
        $user_meta = $job->user->userMeta;

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $user_meta->city ?? null,
            'customer_type' => $user_meta->customer_type ?? null,
        ];

        [$due_date, $due_time] = explode(" ", $job->due);
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];
        if ($job->gender !== null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');

        return true;
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $response = ['status' => 'fail'];
        $job = Job::find($data['job_id']);
        $cuser = $user;

        if (Job::isTranslatorAlreadyBooked($job->id, $cuser->id, $job->due)) {
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
            return $response;
        }

        if ($job->status !== 'pending' || !Job::insertTranslatorJobRel($cuser->id, $job->id)) {
            $response['message'] = 'Could not accept the job.';
            return $response;
        }

        $job->status = 'assigned';
        $job->save();

        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        $mailer = new AppMailer();
        $data = [
            'user' => $user,
            'job'  => $job
        ];

        try {
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data); 
            $jobs = $this->getPotentialJobs($cuser);
    
            return [
                'status' => 'success',
                'list' => json_encode(['jobs' => $jobs, 'job' => $job], true)
            ];   
        } catch ( \Exception $e ) {
            return $response;
        }  
    }

    /*Function to accept the job with the job id*/
    /**
     * @param $data
     * @param $cuser
     * @return mixed
     */
    public function acceptJobWithId($data, $cuser)
    {
        $job_id = $data['job_id'];
        $job = Job::find($job_id);
        $response = array();

        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            $response = [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'
            ];

            return $response;
        }

        if ($job->status != 'pending' || !Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $response = [
                'status' => 'fail',
                'message' => 'Denna ' . $language . ' tolkning ' . $job->duration . ' min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning'
            ];

            return $response;
        }

        $job->status = 'assigned';
        $job->save();

        $user = $job->user;
        $mailer = new AppMailer();

        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];

        try {
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
        } catch ( \Exception $e ) {
            $response = [
                'status' => 'fail',
                'message' => 'Error in sending email'
            ];
        }  

        $data = [
            'notification_type' => 'job_accepted',
        ];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];
        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }

        $response = [
            'status' => 'success',
            'list' => [
                'job' => $job
            ],
            'message' => 'Du har nu accepterat och fått bokningen för ' . $language . ' tolk ' . $job->duration . 'min ' . $job->due
        ];
    
        return $response;
    }

    /**
     * @param $data
     * @param $user
     * @return mixed
     */
    public function cancelJobAjax($data, $user)
    {
        $response = [];

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::find($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = now();

            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
            } else {
                $job->status = 'withdrawafter24';
            }

            $job->save();
            Event::fire(new JobWasCanceled($job));

            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $notificationData = [
                    'notification_type' => 'job_cancelled',
                    'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                    'msg_text' => [
                        "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                    ]
                ];

                if ($this->isNeedToSendPush($translator->id)) {
                    $this->sendPushNotificationToSpecificUsers([$translator], $job_id, $notificationData);
                }
            }
        } else {
            if ($job->due->diffInHours(now()) > 24) {
                $customer = $job->user()->first();

                if ($customer) {
                    $notificationData = [
                        'notification_type' => 'job_cancelled',
                        'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                        'msg_text' => [
                            "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                        ]
                    ];

                    if ($this->isNeedToSendPush($customer->id)) {
                        $this->sendPushNotificationToSpecificUsers([$customer], $job_id, $notificationData);
                    }
                }

                $job->status = 'pending';
                $job->created_at = now();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id);

                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }

        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    /**
     * @param $cuser
     * @return mixed
     */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = match ($cuser_meta->translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid',
        };

        $languages = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($specific_job === 'SpecificJob' && $check_particular_job === 'userCanNotAcceptJob') {
                unset($job_ids[$k]);
            }

            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '') && $job->customer_physical_type === 'yes' && !$checktown) {
                unset($job_ids[$k]);
            }
        }
        
        return $job_ids;
    }

    /**
     * @param $post_data
     * @return mixed
     */
    public function customerNotCall($post_data)
    {
        $completeddate = now()->format('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $interval = now()->diffInRealHours($duedate);

        $job = $job_detail;
        $job->end_at = $completeddate;
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;

        $job->save();
        $tr->save();

        return [
            'status' => 'success'
        ];
    }

    /**
     * @param $request
     * @param $limit
     * @return mixed
     */
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumerType = $cuser->consumer_type;

        $query = Job::query()
            ->orderByDesc('created_at')
            ->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');


        if ($cuser && $cuser->user_type == config('auth.superAdminRoleId')) {
            $query = $this->applySuperAdminFilters($query, $requestdata);
        } else {
            $query = $this->applyNonSuperAdminFilters($query, $requestdata, $consumerType);
        }

        return $limit === 'all' ? $query->get() : $query->paginate(15);
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    /**
     * @param $request
     * @return mixed
     */
    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);

        $data = [
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job->due, now()),
            'updated_at' => now(),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => now()
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job->due, now())
        ];

        if ($job->status != 'timedout') {
            $affectedRows = Job::where('id', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $jobData = [
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
                'will_expire_at' => TeHelper::willExpireAt($job->due, now()),
                'cust_16_hour_email' => 0,
                'cust_48_hour_email' => 0,
                'admin_comments' => 'This booking is a reopening of booking #' . $jobid
            ];
    
            $newJob = Job::create($jobData);
            $affectedRows = $newJob ? 1 : 0;
            $new_jobid = $newJob->id;
        }

        if ($affectedRows) {
            Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
            Translator::create($data);
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

        /**
     * @param $query
     * @param $requestdata
     * @return mixed
     */
    private function applySuperAdminFilters($query, $requestdata)
    {
        return $query->where(function ($query) use ($requestdata) {
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $query->where('ignore_feedback', 0)
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', 3);
                    });
            }

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                if (is_array($requestdata['id']))
                    $query->whereIn('id', $requestdata['id']);
                else
                    $query->where('id', $requestdata['id']);
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $query->whereIn('from_language_id', $requestdata['lang']);
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $query->whereIn('status', $requestdata['status']);
            }

            if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') { 
                $query->where('expired_at', '>=', $requestdata['expired_at']);
            }

            if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
                $query->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            }

            if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
                if ($users) {
                    $query->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $query->whereIn('id', $allJobIDs);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $query->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $query->where('created_at', '<=', $to);
                }
                $query->orderBy('created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $query->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $query->where('due', '<=', $to);
                }
                $query->orderBy('due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $query->whereIn('job_type', $requestdata['job_type']);
            }

            if (isset($requestdata['physical'])) {
                $query->where('customer_physical_type', $requestdata['physical']);
                $query->where('ignore_physical', 0);
            }

            if (isset($requestdata['phone'])) {
                $query->where('customer_phone_type', $requestdata['phone']);
                if(isset($requestdata['physical']))
                $query->where('ignore_physical_phone', 0);
            }

            if (isset($requestdata['flagged'])) {
                $query->where('flagged', $requestdata['flagged']);
                $query->where('ignore_flagged', 0);
            }

            if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
                $query->whereDoesntHave('distance');
            }

            if(isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
                $query->whereDoesntHave('user.salaries');
            }

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
                $query = $query->count();

                return ['count' => $query];
            }

            if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
                $query->whereHas('user.userMeta', function($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            }

            if (isset($requestdata['booking_type'])) {
                if ($requestdata['booking_type'] == 'physical')
                    $query->where('customer_physical_type', 'yes');
                if ($requestdata['booking_type'] == 'phone')
                    $query->where('customer_phone_type', 'yes');
            }

            return $query;
        });
    }

    /**
     * @param $query
     * @param $requestdata
     * @return mixed
     */
    private function applyNonSuperAdminFilters($query, $requestdata, $consumerType)
    {
        return $query->where(function ($query) use ($requestdata, $consumerType) {
            if ($consumerType === 'RWS') {
                $query->where('job_type', 'rws');
            } else {
                $query->where('job_type', 'unpaid');
            }

            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $query->where('ignore_feedback', 0)
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', 3);
                    });
            }

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $query->where('id', $requestdata['id']);
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $query->whereIn('from_language_id', $requestdata['lang']);
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $query->whereIn('status', $requestdata['status']);
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $query->whereIn('job_type', $requestdata['job_type']);
            }

            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $query->where('user_id', '=', $user->id);
                }
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $query->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $query->where('created_at', '<=', $to);
                }
                $query->orderBy('created_at', 'desc');
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $query->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $query->where('due', '<=', $to);
                }
                $query->orderBy('due', 'desc');
            }

            return $query;
        });
    }

}