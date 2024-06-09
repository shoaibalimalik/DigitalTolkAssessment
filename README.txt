ISSUES IN CONTROLLER
=====================

- typehinting is missing
- logic is added in the controller
- queries are directly used in controller
- validation is missing
- inconsistent response structure
- inaccurate readability
- inconsistent parameter names like at some places it's job_id and at some places it's jobid,

=====================
   I have added a form request 'JobActionRequest' which is used for 4 functions  [ acceptJob, acceptJobWithId, customerNotCall, cancelJob]   
   but in resendNotifications method it is again retrieved is jobId so i will prompt the front end to pass job_id and will use the 
   same JobActionRequest as well.
=====================
 
 
Improvements made in the controller



index method
=====================

- moved userid outside if confition for better readability
- added null check for $request->__authenticatedUser->user_type becase $request->__authenticatedUser may not exist and directly 
  fetching user_type from it will throw error but ideally there will be a middleware that will check if __authenticatedUser exists 
  when a request is made.
- extracted $request->__authenticatedUser->user_type to a variable beacuse it was used twice in if condition and the code doens't look very readable
- moved env variable to config/auth.php as it is not a recommended way to directly get env variables in the controllers
- if no record found returning proper error message with status code.
- synchrononized the response object to always return json , it will always contain 'data' and 'message' so the response strutuce is always consistent


show method
=====================

- added the query in the repository in the method getJobById() so the controller is clean
- checking if the job was not found then returning the error message
- otherwise returning the job object



store method
=====================

- added a form request, we can add validation in controllers as well but for seperation of concerns and following the standard I have used form request
- since it is a database call i have also added try catch as we may get an error due to any reason, server timeout, database inavailibility, query exception anything so I am handling it using try catch
- an then i am returning json and the response will also be structured



update method
=====================

- added a form request, we can add validation in controllers as well but for seperation of concerns and following the standard I have used form request
- since it is a database call i have also added try catch as we may get an error due to any reason, server timeout, database inavailibility, query exception anything so I am handling it using try catch
- an then i am returning json and the response will also be structured



immediateJobEmail method
=====================

- removing unused variable $adminSenderEmail
- added a form request, we can add validation in controllers as well but for seperation of concerns and following the standard I have used form request, it is also updating the job method, we could have used the storejobrequest, but it is possible that we might need to validate only 1, 2 fields for this 
- request, not the entire job object so i have added a seperate form request to validate this to keep the code independent and loosely coupled.
- since it is a database call i have also added try catch as we may get an error due to any reason, server timeout, database inavailibility, query exception anything so I am handling it using try catch
- an then i am returning json and the response will also be structured



getHistory method
=====================

- moved userid outside if confition for better readability
- if no record found returning proper error message with status code.
- synchrononized the response object to always return json , it will always contain 'data', 'error' and 'message' so the response strutuce is always consistent



acceptJob method
=====================

- added a form request
- and returning json as the keys are already being returned from the repository method, just making sure that json is returned.



acceptJobWithId method
=====================

- using the same form request which i used for acceptJob method as looking at the code both functions are using same field job_id so we can validate it in one form request and reuse it, in form request we can also make sure that this job_id exists in jobs table
- and returning json as the keys are already being returned from the repository method, just making sure that json is returned.
 
 

cancelJob method
=====================

- added a form request
- and returning json as the keys are already being returned from the repository method, just making sure that json is returned.
 


endJob method
=====================

- added a form request
- and returning json as the keys are already being returned from the repository method, just making sure that json is returned.



customerNotCall method
=====================

- added a form request
- and returning json as the keys are already being returned from the repository method, just making sure that json is returned.



getPotentialJobs method
=====================

- $data is not being used so removing it
- and making sure that response is returned as json



distancefeed method
=====================

- adding typehinting
- adding a new form request and adding the rules by looking at the method
- flagged property is required in the form request
- and if flagged is set to true admincomment property will be required
- removing if else and using php shorthand instead of if else
- moving the queries to the respositries



reopen method
=====================

- added typehinting
- added form request
- making sure that json response is returned.



resendNotifications method
=====================

- adding typehinting
- added form request
- will enforce this method to receive the param as job_id not as jobId to keep the code consistent
- moved the logic to the repository in single method
- making sure that json response is returned.

 

resendSMSNotifications
=====================

- adding typehinting
- added form request
- will enforce this method to receive the param as job_id not as jobId to keep the code consistent
- moved the logic to the repository in single method
- added try catch block and returned json response
- added enums for status code








=====================
ISSUES IN REPOSITORY
=====================

- usage of env function directly
- disorganized code
- long functions
- too much native php code
- duplicate code
- excessive usage of if else where match operator could be used
- validations for paramter which should be done in form requests
- no exception handling
- no typehinting on methods







Improvements made in the repository



getUsersJobs method
=====================

- just organized the code for better readability 
- added an early return is user is not found
- used collection to filter emergencyJobs and normalJobs after grouping jobs By  'immediate'



getUsersJobsHistory method
=====================

- just organized the code for better readability 
- added an early return is user is not found
- single return for both if conditions



store method
=====================

- improved readability
- removed unused code
- wrote short code by using optimized php standard features.
- added exception handling for query



storeJobEmail method
=====================

- improved readability
- removed unused code
- wrote short code by using optimized php standard features.
- added exception handling for query



jobToData method
=====================

- improved readability
- removed unused code
- wrote short code by using optimized php standard features.
- code now looks clean



getPotentialJobIdsWithUserId method
=====================

- improved readability
- used match operator instead of multiple if else
- wrote collection filter method instead of foreach to use native laravel feature for removing unwanted items



sendNotificationTranslator method
=====================

- updated users query for more optimized results
- removed unwanted conditions and optimized the code
- improved readability



sendSMSNotificationToTranslator method
=====================

- removed unwanted conditions and optimized the code
- improved readability
- moved env function call to config.app and getting the value from config now



sendPushNotificationToSpecificUsers method
=====================

- removed unwanted code
- improved readability
- moved env function call to config.app and getting the value from config now 



getPotentialTranslators method
=====================

- removed unwanted code
- improved readability
- added match operator instead of if else for clean code



updateJob method
=====================

- removed unwanted code
- improved readability
- moved relevant code together



changeStatus method
=====================

- added an early return
- removed extra code
- added logic to dynamically call a method after noting a pattern in function names
- improved readability



changeTimedoutStatus method
=====================

- added an early return
- removed extra code
- improved readability



changeCompletedStatus method
=====================

- removing if condition as form validation is in place on updateJob controller action



changeWithdrawafter24Status method
=====================

- removing if condition as form validation is in place on updateJob controller action



changeStartedStatus method
=====================

- removed extra code
- improved readability
- added try catch for the code which sends out emails



changePendingStatus method
=====================

- removing if condition as form validation is in place on updateJob controller action
- improved readability
- organized the code
- added try catch for the code which sends out emails



changeWithdrawafter24Status method
=====================

- improved readability



changeAssignedStatus method
=====================

- added an early return
- improved readability
- organized the code
- added try catch for the code which sends out emails



changeTranslator method
=====================

- improved readability
- organized the code
- removed extra code



sendChangedTranslatorNotification method
=====================

- improved readability
- organized the code
- removed extra code
- added try catch for the code which sends out emails



sendChangedDateNotification method
=====================

- improved readability
- organized the code
- removed extra code
- added try catch for the code which sends out emails



sendChangedLangNotification method
=====================

- improved readability
- organized the code
- removed extra code
- added try catch for the code which sends out emails



sendNotificationByAdminCancelJob  method
=====================

- improved readability
- organized the code
- removed extra code



acceptJob method
=====================

- improved readability
- added early returns
- organized the code
- removed extra code
- added try catch for the code which sends out email



acceptJobWithId method
=====================

- improved readability
- added early returns
- organized the code
- removed extra code
- added try catch for the code which sends out email



customerNotCall method
=====================

- improved readability
- organized the code



cancelJobAjax method
=====================

 - improved readability
 - organized the code



getPotentialJobs method
=====================

- improved readability
- organized the code
- used simple match operator instead of if else



getAll method
=====================

- improved readability
- organized the code
- indentided duplocate code and extracted it as a common code for both conditions
- created two functions for building query one for admin and one for non admin
- now it's easier to debug and make change in the code



reopen method
=====================

- improved readability
- organized the code
- removed extra code


-- Removing unused function jobEnd from the repository
-- Removing unused function  sendExpiredNotification from the repository
-- Removing unused function  sendNotificationChangePending from the repository
-- Removing unused function  alerts from the repository
-- Removing unused function bookingExpireNoAccepted from the repository
-- Removing unused function sendSessionStartRemindNotification  from the repository








Also please note some form request may be i haven't added rules for all as there are no models, I just wanted to give an idea that this is how it should work.


