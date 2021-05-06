<?php

require_once('db.php');
require_once('../model/task.php');
require_once('../model/response.php');
require_once('../model/image.php');

// function to get task images for given task id and user, returns an array of images
function retrieveTaskImages($dbConn, $taskid, $returned_userid) {

  // ADD AUTH TO QUERY
  // create db query to get task images
  $imageQuery = $dbConn->prepare('SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid from tblimages, tbltasks where tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
  $imageQuery->bindParam(':taskid', $taskid, PDO::PARAM_INT);
  $imageQuery->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
  $imageQuery->execute();

  // create image array to store returned images
  $imageArray = array();

  // while there are images in the table for this task
  while($imageRow = $imageQuery->fetch(PDO::FETCH_ASSOC)) {
    // create new image object from what has been returned from database
    $image = new Image($imageRow['id'], $imageRow['title'], $imageRow['filename'], $imageRow['mimetype'], $imageRow['taskid']);
    // create image and store in array for return in json data
    $imageArray[] = $image->returnImageAsArray();
  }

  return $imageArray;
}

// attempt to set up connections to read and write db connections
try {
  $writeDB = DB::connectWriteDB();
  $readDB = DB::connectReadDB();
}
catch(PDOException $ex) {
  // log connection error for troubleshooting and return a json error response
  error_log("Connection Error: ".$ex, 0);
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage("Database connection error");
  $response->send();
  exit;
}

// BEGIN OF AUTH SCRIPT
// Authenticate user with access token
// check to see if access token is provided in the HTTP Authorization header and that the value is longer than 0 chars
// don't forget the Apache fix in .htaccess file
if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
  $response = new Response();
  $response->setHttpStatusCode(401);
  $response->setSuccess(false);
  (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access token is missing from the header") : false);
  (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);
  $response->send();
  exit;
}

// get supplied access token from authorisation header - used for delete (log out) and patch (refresh)
$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

// attempt to query the database to check token details - use write connection as it needs to be synchronous for token
try {
  // create db query to check access token is equal to the one provided
  $query = $writeDB->prepare('select userid, accesstokenexpiry, useractive, loginattempts from tblsessions, tblusers where tblsessions.userid = tblusers.id and accesstoken = :accesstoken');
  $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
  $query->execute();

  // get row count
  $rowCount = $query->rowCount();

  if($rowCount === 0) {
    // set up response for unsuccessful log out response
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("Invalid access token");
    $response->send();
    exit;
  }

  // get returned row
  $row = $query->fetch(PDO::FETCH_ASSOC);

  // save returned details into variables
  $returned_userid = $row['userid'];
  $returned_accesstokenexpiry = $row['accesstokenexpiry'];
  $returned_useractive = $row['useractive'];
  $returned_loginattempts = $row['loginattempts'];

  // check if account is active
  if($returned_useractive != 'Y') {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("User account is not active");
    $response->send();
    exit;
  }

  // check if account is locked out
  if($returned_loginattempts >= 3) {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("User account is currently locked out");
    $response->send();
    exit;
  }

  // check if access token has expired
  if(strtotime($returned_accesstokenexpiry) < time()) {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage("Access token has expired");
    $response->send();
    exit;
  }
}
catch(PDOException $ex) {
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage("There was an issue authenticating - please try again");
  $response->send();
  exit;
}

// END OF AUTH SCRIPT

// within this if/elseif statement, it is important to get the correct order (if query string GET param is used in multiple routes)

// check if taskid is in the url e.g. /tasks/1
if (array_key_exists("taskid",$_GET)) {
  // get task id from query string
  $taskid = $_GET['taskid'];

  //check to see if task id in query string is not empty and is number, if not return json error
  if($taskid == '' || !is_numeric($taskid)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Task ID cannot be blank or must be numeric");
    $response->send();
    exit;
  }

  // if request is a GET, e.g. get task
  if($_SERVER['REQUEST_METHOD'] === 'GET') {
    // attempt to query the database
    try {
      // create db query
      // ADD AUTH TO QUERY
      $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
  		$query->execute();

      // get row count
      $rowCount = $query->rowCount();

      // create task array to store returned task
      $taskArray = array();

      if($rowCount === 0) {
        // set up response for unsuccessful return
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("Task not found");
        $response->send();
        exit;
      }

      // for each row returned
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {

        // get Task Images for given task, store them in array to pass to task
        $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_userid);

        // create new task object for each row
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);

        // create task and store in array for return in json data
  	    $taskArray[] = $task->returnTaskAsArray();
      }

      // bundle tasks and rows returned into an array to return in the json data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      // set up response for successful return
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit;
    }
    // if error with sql query return a json error
    catch(TaskException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(ImageException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $ex) {
      error_log("Database Query Error: ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to get task");
      $response->send();
      exit;
    }
  }
  // else if request if a DELETE e.g. delete task
  elseif($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // attempt to query the database
    try {
      // ADD AUTH TO QUERY
      // create db query to get task images - if task id doesnt exist then will return 0 rows
      $imageSelectQuery = $readDB->prepare('SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid from tblimages, tbltasks where tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
      $imageSelectQuery->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $imageSelectQuery->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $imageSelectQuery->execute();

      // while there are images in the table for this task - will not run if 0 rows are returned
      while($imageRow = $imageSelectQuery->fetch(PDO::FETCH_ASSOC)) {
        // begin transaction as we dont want the row to be deleted if the file cannot be deleted
        $writeDB->beginTransaction();
        // create new image object from what has been returned from database
        $image = new Image($imageRow['id'], $imageRow['title'], $imageRow['filename'], $imageRow['mimetype'], $imageRow['taskid']);

        // store image id
        $imageID = $image->getID();
        // delete all images query
        $query = $writeDB->prepare('delete tblimages from tblimages, tbltasks where tblimages.id = :imageid and tblimages.taskid = :taskid and tblimages.taskid = tbltasks.id and tbltasks.userid = :userid');
        $query->bindParam(':imageid', $imageID, PDO::PARAM_INT);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        // delete the returned image
        $image->deleteImageFile();

        // commit the successful image deletion
        $writeDB->commit();
      }

      // once all images are deleted then delete the actual task
      $query = $writeDB->prepare('delete from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      // get row count
      $rowCount = $query->rowCount();

      if($rowCount === 0) {
        // set up response for unsuccessful return
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("Task not found");
        $response->send();
        exit;
      }

      // set task image folder location
      $taskImageFolder = "../../../taskimages/".$taskid;

      // check to see if it exists
      if(is_dir($taskImageFolder)) {
        // if folder does exist and all files are deleted then delete the task id folder
        rmdir($taskImageFolder);
      }

      // set up response for successful return
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage("Task deleted");
      $response->send();
      exit;
    }
    // if error with sql query return a json error
    catch(ImageException $ex) {
      // rollback transactions if any outstanding transactions are present
      if($writeDB->inTransaction()) {
        $writeDB->rollBack();
      }
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $ex) {
      // rollback transactions if any outstanding transactions are present
      if($writeDB->inTransaction()) {
        $writeDB->rollBack();
      }
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to delete task");
      $response->send();
      exit;
    }
  }
  // handle updating task
  elseif($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    // update task
    try {
      // check request's content type header is JSON
      if($_SERVER['CONTENT_TYPE'] !== 'application/json') {
        // set up response for unsuccessful request
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Content Type header not set to JSON");
        $response->send();
        exit;
      }

      // get PATCH request body as the PATCHed data will be JSON format
      $rawPatchData = file_get_contents('php://input');

      if(!$jsonData = json_decode($rawPatchData)) {
        // set up response for unsuccessful request
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Request body is not valid JSON");
        $response->send();
        exit;
      }

      // set task field updated to false initially
      $title_updated = false;
      $description_updated = false;
      $deadline_updated = false;
      $completed_updated = false;

      // create blank query fields string to append each field to
      $queryFields = "";

      // check if title exists in PATCH
      if(isset($jsonData->title)) {
        // set title field updated to true
        $title_updated = true;
        // add title field to query field string
        $queryFields .= "title = :title, ";
      }

      // check if description exists in PATCH
      if(isset($jsonData->description)) {
        // set description field updated to true
        $description_updated = true;
        // add description field to query field string
        $queryFields .= "description = :description, ";
      }

      // check if deadline exists in PATCH
      if(isset($jsonData->deadline)) {
        // set deadline field updated to true
        $deadline_updated = true;
        // add deadline field to query field string
        $queryFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), ";
      }

      // check if completed exists in PATCH
      if(isset($jsonData->completed)) {
        // set completed field updated to true
        $completed_updated = true;
        // add completed field to query field string
        $queryFields .= "completed = :completed, ";
      }

      // remove the right hand comma and trailing space
      $queryFields = rtrim($queryFields, ", ");

      // check if any task fields supplied in JSON
      if($title_updated === false && $description_updated === false && $deadline_updated === false && $completed_updated === false) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("No task fields provided");
        $response->send();
        exit;
      }
      // ADD AUTH TO QUERY
      // create db query to get task from database to update - use master db
      $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      // get row count
      $rowCount = $query->rowCount();

      // make sure that the task exists for a given task id
      if($rowCount === 0) {
        // set up response for unsuccessful return
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("No task found to update");
        $response->send();
        exit;
      }

      // for each row returned - should be just one
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        // create new task object
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
      }
      // ADD AUTH TO QUERY
      // create the query string including any query fields
      $queryString = "update tbltasks set ".$queryFields." where id = :taskid and userid = :userid";
      // prepare the query
      $query = $writeDB->prepare($queryString);

      // if title has been provided
      if($title_updated === true) {
        // set task object title to given value (checks for valid input)
        $task->setTitle($jsonData->title);
        // get the value back as the object could be handling the return of the value differently to
        // what was provided
        $up_title = $task->getTitle();
        // bind the parameter of the new value from the object to the query (prevents SQL injection)
        $query->bindParam(':title', $up_title, PDO::PARAM_STR);
      }

      // if description has been provided
      if($description_updated === true) {
        // set task object description to given value (checks for valid input)
        $task->setDescription($jsonData->description);
        // get the value back as the object could be handling the return of the value differently to
        // what was provided
        $up_description = $task->getDescription();
        // bind the parameter of the new value from the object to the query (prevents SQL injection)
        $query->bindParam(':description', $up_description, PDO::PARAM_STR);
      }

      // if deadline has been provided
      if($deadline_updated === true) {
        // set task object deadline to given value (checks for valid input)
        $task->setDeadline($jsonData->deadline);
        // get the value back as the object could be handling the return of the value differently to
        // what was provided
        $up_deadline = $task->getDeadline();
        // bind the parameter of the new value from the object to the query (prevents SQL injection)
        $query->bindParam(':deadline', $up_deadline, PDO::PARAM_STR);
      }

      // if completed has been provided
      if($completed_updated === true) {
        // set task object completed to given value (checks for valid input)
        $task->setCompleted($jsonData->completed);
        // get the value back as the object could be handling the return of the value differently to
        // what was provided
        $up_completed= $task->getCompleted();
        // bind the parameter of the new value from the object to the query (prevents SQL injection)
        $query->bindParam(':completed', $up_completed, PDO::PARAM_STR);
      }

      // bind the task id provided in the query string
      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      // bind the user id returned
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      // run the query
    	$query->execute();

      // get affected row count
      $rowCount = $query->rowCount();

      // check if row was actually updated, could be that the given values are the same as the stored values
      if($rowCount === 0) {
        // set up response for unsuccessful return
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Task not updated - given values may be the same as the stored values");
        $response->send();
        exit;
      }
      // ADD AUTH TO QUERY
      // create db query to return the newly edited task - connect to master database
      $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      // get row count
      $rowCount = $query->rowCount();

      // check if task was found
      if($rowCount === 0) {
        // set up response for unsuccessful return
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("No task found");
        $response->send();
        exit;
      }
      // create task array to store returned tasks
      $taskArray = array();

      // for each row returned
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {

        // get Task Images for given task, store them in array to pass to task
        $imageArray = retrieveTaskImages($writeDB, $row['id'], $returned_userid);

        // create new task object for each row returned
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);

        // create task and store in array for return in json data
        $taskArray[] = $task->returnTaskAsArray();
      }
      // bundle tasks and rows returned into an array to return in the json data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      // set up response for successful return
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage("Task updated");
      $response->setData($returnData);
      $response->send();
      exit;
    }
    catch(ImageException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(TaskException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    // if error with sql query return a json error
    catch(PDOException $ex) {
      error_log("Database Query Error: ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to update task - check your data for errors");
      $response->send();
      exit;
    }
  }
  // if any other request method apart from GET, PATCH, DELETE is used then return 405 method not allowed
  else {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit;
  }
}
// get tasks that have submitted a completed filter
elseif(array_key_exists("completed",$_GET)) {

  // get completed from query string
  $completed = $_GET['completed'];

  // check to see if completed in query string is either Y or N
  if($completed !== "Y" && $completed !== "N") {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Completed filter must be Y or N");
    $response->send();
    exit;
  }

  if($_SERVER['REQUEST_METHOD'] === 'GET') {
    // attempt to query the database
    try {
      // ADD AUTH TO QUERY
      // create db query
      $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where completed like :completed and userid = :userid');
      $query->bindParam(':completed', $completed, PDO::PARAM_STR);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
  		$query->execute();

      // get row count
      $rowCount = $query->rowCount();

      // create task array to store returned tasks
      $taskArray = array();

      // for each row returned
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {

        // get Task Images for given task, store them in array to pass to task
        $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_userid);

        // create new task object for each row
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);

        // create task and store in array for return in json data
  	    $taskArray[] = $task->returnTaskAsArray();
      }

      // bundle task and rows returned into an array to return in the json data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      // set up response for successful return
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit;
    }
    // if error with sql query return a json error
    catch(TaskException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(ImageException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $ex) {
      error_log("Database Query Error: ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to get task");
      $response->send();
      exit;
    }
  }
  // if any other request method apart from GET is used then return 405 method not allowed
  else {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit;
  }
}
// handle getting all tasks page of 20 at a time
elseif(array_key_exists("page",$_GET)) {

    // if request is a GET e.g. get tasks
  if($_SERVER['REQUEST_METHOD'] === 'GET') {

    // get page id from query string
    $page = $_GET['page'];

    //check to see if page id in query string is not empty and is number, if not return json error
    if($page == '' || !is_numeric($page)) {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage("Page number cannot be blank and must be numeric");
      $response->send();
      exit;
    }

    // set limit to 20 per page
    $limitPerPage = 20;

    // attempt to query the database
    try {
      // ADD AUTH TO QUERY

      // get total number of tasks for user
      // create db query
      $query = $readDB->prepare('SELECT count(id) as totalNoOfTasks from tbltasks where userid = :userid');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      // get row for count total
      $row = $query->fetch(PDO::FETCH_ASSOC);

      $tasksCount = intval($row['totalNoOfTasks']);

      // get number of pages required for total results use ceil to round up
      $numOfPages = ceil($tasksCount/$limitPerPage);

      // if no rows returned then always allow page 1 to show a successful response with 0 tasks
      if($numOfPages == 0){
        $numOfPages = 1;
      }

      // if passed in page number is greater than total number of pages available or page is 0 then 404 error - page not found
      if($page > $numOfPages || $page == 0) {
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage("Page not found");
        $response->send();
        exit;
      }

      // set offset based on current page, e.g. page 1 = offset 0, page 2 = offset 20
      $offset = ($page == 1 ?  0 : (20*($page-1)));

      // ADD AUTH TO QUERY
      // get rows for page
      // create db query
      $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where userid = :userid limit :pglimit OFFSET :offset');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->bindParam(':pglimit', $limitPerPage, PDO::PARAM_INT);
      $query->bindParam(':offset', $offset, PDO::PARAM_INT);
      $query->execute();

      // get row count
      $rowCount = $query->rowCount();

      // create task array to store returned tasks
      $taskArray = array();

      // for each row returned
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {

        // get Task Images for given task, store them in array to pass to task
        $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_userid);

        // create new task object for each row
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);

        // create task and store in array for return in json data
        $taskArray[] = $task->returnTaskAsArray();
      }

      // bundle tasks and rows returned into an array to return in the json data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['total_rows'] = $tasksCount;
      $returnData['total_pages'] = $numOfPages;
      // if passed in page less than total pages then return true
      ($page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false);
      // if passed in page greater than 1 then return true
      ($page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false);
      $returnData['tasks'] = $taskArray;

      // set up response for successful return
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit;
    }
    // if error with sql query return a json error
    catch(TaskException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(ImageException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $ex) {
      error_log("Database Query Error: ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to get tasks");
      $response->send();
      exit;
    }
  }
  // if any other request method apart from GET is used then return 405 method not allowed
  else {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit;
  }
}
// handle getting all tasks or creating a new one
elseif(empty($_GET)) {

  // if request is a GET e.g. get tasks
  if($_SERVER['REQUEST_METHOD'] === 'GET') {

    // attempt to query the database
    try {
      // ADD AUTH TO QUERY
      // create db query
      $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where userid = :userid');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      // get row count
      $rowCount = $query->rowCount();

      // create task array to store returned tasks
      $taskArray = array();

      // for each row returned
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {

        // get Task Images for given task, store them in array to pass to task
        $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_userid);

        // create new task object for each row
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);

        // create task and store in array for return in json data
        $taskArray[] = $task->returnTaskAsArray();
      }

      // bundle tasks and rows returned into an array to return in the json data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      // set up response for successful return
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit;
    }
    // if error with sql query return a json error
    catch(TaskException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    // if error with image object  return a json error
    catch(ImageException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    catch(PDOException $ex) {
      error_log("Database Query Error: ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to get tasks");
      $response->send();
      exit;
    }
  }
  // else if request is a POST e.g. create task
  elseif($_SERVER['REQUEST_METHOD'] === 'POST') {

    // create task
    try {
      // check request's content type header is JSON
      if($_SERVER['CONTENT_TYPE'] !== 'application/json') {
        // set up response for unsuccessful request
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Content Type header not set to JSON");
        $response->send();
        exit;
      }

      // get POST request body as the POSTed data will be JSON format
      $rawPostData = file_get_contents('php://input');

      if(!$jsonData = json_decode($rawPostData)) {
        // set up response for unsuccessful request
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Request body is not valid JSON");
        $response->send();
        exit;
      }

      // check if post request contains title and completed data in body as these are mandatory
      if(!isset($jsonData->title) || !isset($jsonData->completed)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        (!isset($jsonData->title) ? $response->addMessage("Title field is mandatory and must be provided") : false);
        (!isset($jsonData->completed) ? $response->addMessage("Completed field is mandatory and must be provided") : false);
        $response->send();
        exit;
      }

      // create new task with data, if non mandatory fields not provided then set to null
      $newTask = new Task(null, $jsonData->title, (isset($jsonData->description) ? $jsonData->description : null), (isset($jsonData->deadline) ? $jsonData->deadline : null), $jsonData->completed);
      // get title, description, deadline, completed and store them in variables
      $title = $newTask->getTitle();
      $description = $newTask->getDescription();
      $deadline = $newTask->getDeadline();
      $completed = $newTask->getCompleted();

      // ADD AUTH TO QUERY
      // create db query
      $query = $writeDB->prepare('insert into tbltasks (title, description, deadline, completed, userid) values (:title, :description, STR_TO_DATE(:deadline, \'%d/%m/%Y %H:%i\'), :completed, :userid)');
      $query->bindParam(':title', $title, PDO::PARAM_STR);
      $query->bindParam(':description', $description, PDO::PARAM_STR);
      $query->bindParam(':deadline', $deadline, PDO::PARAM_STR);
      $query->bindParam(':completed', $completed, PDO::PARAM_STR);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      // get row count
      $rowCount = $query->rowCount();

      // check if row was actually inserted, PDO exception should have caught it if not.
      if($rowCount === 0) {
        // set up response for unsuccessful return
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("Failed to create task");
        $response->send();
        exit;
      }

      // get last task id so we can return the Task in the json
      $lastTaskID = $writeDB->lastInsertId();
      // ADD AUTH TO QUERY
      // create db query to get newly created task - get from master db not read slave as replication may be too slow for successful read
      $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid and userid = :userid');
      $query->bindParam(':taskid', $lastTaskID, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      // get row count
      $rowCount = $query->rowCount();

      // make sure that the new task was returned
      if($rowCount === 0) {
        // set up response for unsuccessful return
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("Failed to retrieve task after creation");
        $response->send();
        exit;
      }

      // create empty array to store tasks
      $taskArray = array();

      // for each row returned - should be just one
      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        // create new task object
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);

        // create task and store in array for return in json data
        $taskArray[] = $task->returnTaskAsArray();
      }
      // bundle tasks and rows returned into an array to return in the json data
      $returnData = array();
      $returnData['rows_returned'] = $rowCount;
      $returnData['tasks'] = $taskArray;

      //set up response for successful return
      $response = new Response();
      $response->setHttpStatusCode(201);
      $response->setSuccess(true);
      $response->addMessage("Task created");
      $response->setData($returnData);
      $response->send();
      exit;
    }
    // if task fails to create due to data types, missing fields or invalid data then send error json
    catch(TaskException $ex) {
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit;
    }
    // if error with sql query return a json error
    catch(PDOException $ex) {
      error_log("Database Query Error: ".$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage("Failed to insert task into database - check submitted data for errors");
      $response->send();
      exit;
    }
  }
  // if any other request method apart from GET or POST is used then return 405 method not allowed
  else {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit;
  }
}
// return 404 error if endpoint not available
else {
  $response = new Response();
  $response->setHttpStatusCode(404);
  $response->setSuccess(false);
  $response->addMessage("Endpoint not found");
  $response->send();
  exit;
}
