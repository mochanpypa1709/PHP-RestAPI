<?php

require_once('db.php');
require_once('../model/response.php');
require_once('../model/image.php');


// ------------- FUNCTIONS -----------------

//  function to send response
function sendResponse($statusCode, $success, $message = null, $toCache = false, $data = null) {
  // set up response object
  $response = new Response();
  $response->setHttpStatusCode($statusCode);
  $response->setSuccess($success);
  // check if message has been supplied, if so then add it to the response
  if($message != null) {
    $response->addMessage($message);
  }
  $response->toCache($toCache);
  // check if data has been supplied, if so then add it to the response
  if($data != null) {
    $response->setData($data);
  }
  $response->send();
  exit;
}

// function to check authorisation status
function checkAuthStatusAndReturnUserID($writeDB) {

  // BEGIN OF AUTH SCRIPT
  // Authenticate user with access token
  // check to see if access token is provided in the HTTP Authorization header and that the value is longer than 0 chars
  // don't forget the Apache fix in .htaccess file
  if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {

    $message = null;
    // check if authorization header has been sent
    if(!isset($_SERVER['HTTP_AUTHORIZATION'])) {
      $message = "Access token is missing from the header";
    } else {
      // check if authorization header value is not blank
      if(strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
          $message = "Access token cannot be blank";
      }
    }

    sendResponse(401, false, $message);
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
      // send json error response using function for unsuccessful log out response
      sendResponse(401, false, "Invalid access token");
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
      // send json error response using function
      sendResponse(401, false, "User account is not active");
    }

    // check if account is locked out
    if($returned_loginattempts >= 3) {
      // send json error response using function
      sendResponse(401, false, "User account is currently locked out");
    }

    // check if access token has expired
    if(strtotime($returned_accesstokenexpiry) < time()) {
      // send json error response using function
      sendResponse(401, false, "Access token has expired");
    }
    return $returned_userid;
  }
  catch(PDOException $ex) {
    // send json error response using function
    sendResponse(500, false, "There was an issue authenticating - please try again");
  }

  // END OF AUTH SCRIPT

}

// function to get image
function getImageRoute($readDB, $taskid, $imageid, $returned_userid) {
  // attempt to query the database
  try {
    // create db query
    // ADD AUTH TO QUERY
    $query = $readDB->prepare('SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
    $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    if($rowCount === 0) {
      // send json error response using function
      sendResponse(404, false, "Image not found");
    }

    // set temp image to null
    $image = null;

    // for each row returned
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      // create new task object for each row
      $image = new Image($row['id'], $row['title'], $row['filename'], $row['mimetype'], $row['taskid']);
    }

    // check if image is null, if so then error
    if($image == null) {
      // send json error response using function
      sendResponse(404, false, "Image not found");
    }

    // send image to client
    $image->returnImageFile();
  }
  // if error with sql query return a json error
  catch(ImageException $ex) {
    // send json error response using function
    sendResponse(500, false, $ex->getMessage());
  }
  catch(PDOException $ex) {
    error_log("Database Query Error: ".$ex, 0);
    // send json error response using function
    sendResponse(500, false, "Error getting image");
  }
}

// function to get image attributes
function getImageAttributesRoute($readDB, $taskid, $imageid, $returned_userid) {
  // attempt to query the database
  try {
    // create db query
    // ADD AUTH TO QUERY
    $query = $readDB->prepare('SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
    $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    // create image array to store returned image
    $imageArray = array();

    if($rowCount === 0) {
      // send json error response using function
      sendResponse(404, false, "Image not found");
    }

    // for each row returned
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      // create new image object for each row
      $image = new Image($row['id'], $row['title'], $row['filename'], $row['mimetype'], $row['taskid']);

      // create image and store in array for return in json data
      $imageArray[] = $image->returnImageAsArray();
    }

    // send json success response using function
    sendResponse(200, true, null, true, $imageArray);
  }
  // if error with sql query return a json error
  catch(ImageException $ex) {
    // send json error response using function
    sendResponse(500, false, $ex->getMessage());
  }
  catch(PDOException $ex) {
    error_log("Database Query Error: ".$ex, 0);
    // send json error response using function
    sendResponse(500, false, "Failed to get image attributes");
  }
}

// function to update image attributes
function updateImageAttributesRoute($writeDB, $taskid, $imageid, $returned_userid) {
  // TODO add check for image filename already existing? and add functionality to rename physical file if filename is provided
  // should be done as a transaction as if physical file rename fails then the database row should not be updated
  try {
    // check request's content type header is JSON
    if($_SERVER['CONTENT_TYPE'] !== 'application/json') {
      // send json error response using function
      sendResponse(400, false, "Content Type header not set to JSON");
    }

    // get PATCH request body as the PATCHed data will be JSON format
    $rawPatchData = file_get_contents('php://input');

    if(!$jsonData = json_decode($rawPatchData)) {
      // send json error response using function
      sendResponse(400, false, "Request body is not valid JSON");
    }

    // set image attribute field updated to false initially
    $title_updated = false;
    $filename_updated = false;

    // create blank query fields string to append each field to
    $queryFields = "";

    // check if title exists in PATCH
    if(isset($jsonData->title)) {
      // set title field updated to true
      $title_updated = true;
      // add title field to query field string
      $queryFields .= "tblimages.title = :title, ";
    }

    // check if filename exists in PATCH
    if(isset($jsonData->filename)) {
      // check if filename contains a dot (do not enter file extension within the filename as we will reuse the existing stored extension)
      if(strpos($jsonData->filename, ".") !== false) {
         // send json error response using function
         sendResponse(400, false, "Filename cannot contain any dots (or file extensions) - please remove the dot or file extension");
      }
        // set filename field updated to true
        $filename_updated = true;
        // add description field to query field string
        $queryFields .= "tblimages.filename = :filename, ";
    }

    // remove the right hand comma and trailing space
    $queryFields = rtrim($queryFields, ", ");

    // check if any image attribute fields supplied in JSON
    if($title_updated === false && $filename_updated === false) {
      // send json error response using function
      sendResponse(400, false, "No image fields provided");
    }

    // start transaction so we can roll back any updates if something fails
    $writeDB->beginTransaction();
    // ADD AUTH TO QUERY
    // create db query to get image from database to update - use master db
    $query = $writeDB->prepare('SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid from tblimages, tbltasks where tblimages.id = :imageid and tblimages.taskid = :taskid and tblimages.taskid = tbltasks.id and tbltasks.userid = :userid');
    $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    // make sure that the image exists for a given task and image id
    if($rowCount === 0) {
      // rollback transactions if any outstanding transactions are present
      if($writeDB->inTransaction()) {
        $writeDB->rollBack();
      }
      // send json error response using function
      sendResponse(404, false, "No image found to update");
    }

    // for each row returned - should be just one
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      // create new image object
      $image = new Image($row['id'], $row['title'], $row['filename'], $row['mimetype'], $row['taskid']);
    }

    // ADD AUTH TO QUERY
    // create the query string including any query fields
    $queryString = "update tblimages inner join tbltasks on tblimages.taskid = tbltasks.id set ".$queryFields." where tblimages.id = :imageid and tblimages.taskid = tbltasks.id and tblimages.taskid = :taskid and tbltasks.userid = :userid";
    // prepare the query
    $query = $writeDB->prepare($queryString);

    // if title has been provided
    if($title_updated === true) {
      // set image object title to given value (checks for valid input)
      $image->setTitle($jsonData->title);
      // get the value back as the object could be handling the return of the value differently to
      // what was provided
      $up_title = $image->getTitle();
      // bind the parameter of the new value from the object to the query (prevents SQL injection)
      $query->bindParam(':title', $up_title, PDO::PARAM_STR);
    }

    // if filename has been provided
    if($filename_updated === true) {
      // store original filename so we know which file to rename
      $originalFilename = $image->getFilename();
      // set image object filename to given value (checks for valid input)
      $image->setFilename($jsonData->filename.".".$image->getFileExtension());
      // get the value back as the object could be handling the return of the value differently to
      // what was provided
      $up_filename = $image->getFilename();
      // bind the parameter of the new value from the object to the query (prevents SQL injection)
      $query->bindParam(':filename', $up_filename, PDO::PARAM_STR);
    }

    // bind the image id provided in the query string
    $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
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
      // rollback transactions if any outstanding transactions are present
      if($writeDB->inTransaction()) {
        $writeDB->rollBack();
      }
      // send json error response using function
      sendResponse(400, false, "Image attributes not updated - given values may be the same as the stored values");
    }

    // ADD AUTH TO QUERY
    // create db query to return the newly edited image attributes - connect to master database
    $query = $writeDB->prepare('SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
    $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    // check if image was found
    if($rowCount === 0) {
      // rollback transactions if any outstanding transactions are present
      if($writeDB->inTransaction()) {
        $writeDB->rollBack();
      }
      // send json error response using function
      sendResponse(404, false, "No image found");
    }
    // create image array to store returned image
    $imageArray = array();

    // for each row returned
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      // create new image object
      $image = new Image($row['id'], $row['title'], $row['filename'], $row['mimetype'], $row['taskid']);

      // create image and store in array for return in json data
      $imageArray[] = $image->returnImageAsArray();
    }

    // rename the physical file after all SQL statements have succeeded - DB can roll back if the file rename fails here
    if($filename_updated === true) {
      // rename physical file - will throw an exception if fails
      $image->renameImageFile($originalFilename, $up_filename);
    }

    // if we get to this point everything has been a success so commit database changes
    $writeDB->commit();
    // send json success response using function
    sendResponse(200, true, "Image attributes updated", false, $imageArray);
  }
  catch(ImageException $ex) {
    // rollback transactions if any outstanding transactions are present
    if($writeDB->inTransaction()) {
      $writeDB->rollBack();
    }
    // send json error response using function
    sendResponse(400, false, $ex->getMessage());
  }
  // if error with sql query return a json error
  catch(PDOException $ex) {
    error_log("Database Query Error: ".$ex, 0);
    // rollback transactions if any outstanding transactions are present
    if($writeDB->inTransaction()) {
      $writeDB->rollBack();
    }
    // send json error response using function
    sendResponse(500, false, "Failed to update image - check your data for errors");
  }
}

// function to delete image
function deleteImageRoute($writeDB, $taskid, $imageid, $returned_userid) {
  try {
    // start transaction as we want to also delete a file for a successful return
    $writeDB->beginTransaction();

    // create db query
    // ADD AUTH TO QUERY
    // using writeDB connection for select as we want to get the master details - not any potential delayed replicated info
    $query = $writeDB->prepare('SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
    $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    if($rowCount === 0) {
      // roll back sql transaction
      $writeDB->rollBack();
      // send json error response using function
      sendResponse(404, false, "Image not found");
    }

    // set image as null so we can populate it from the database
    $image = null;

    // for each row returned - should only be one
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      // create new task object for each row
      $image = new Image($row['id'], $row['title'], $row['filename'], $row['mimetype'], $row['taskid']);

    }

    // make sure image is not null
    if($image == null) {
      $writeDB->rollBack();
      // send json error response using function
      sendResponse(500, false, "Failed to get image");
    }

    // delete image row from tblimages
    $query = $writeDB->prepare('delete tblimages from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid and tblimages.taskid = tbltasks.id and tbltasks.userid = :userid');
    $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    if($rowCount === 0) {
      // roll back sql transaction
      $writeDB->rollBack();

      // send json error response using function
      sendResponse(404, false, "Image not found");
    }

    // delete image file
    $image->deleteImageFile();

    // commit sql transaction
    $writeDB->commit();

    // send json success response using function
    sendResponse(200, true, "Image deleted");

  }
  // if error with sql query return a json error
  catch(PDOException $ex) {
    // log connection error for troubleshooting and return a json error response
    error_log("Connection Error: ".$ex, 0);
    // roll back sql transaction
    $writeDB->rollBack();
    // send json error response using function
    sendResponse(500, false, "Failed to delete image");
  }
  // if error with image deletion return as json error
  catch(ImageException $ex) {
    // roll back sql transaction on Image deletion error
    $writeDB->rollBack();
    // send json error response using function
    sendResponse(500, false, $ex->getMessage());
  }
}

// function to upload image
function uploadImageRoute($readDB, $writeDB, $taskid, $returned_userid) {

  try {
    // check request's content type header is multipart/form-data - as using this to upload file data as well as attributes
    // the multipart/form-data content type contains a random boundary to seperate attributes from file data
    // this is why we use strpos to see if it contains the string
    if(!isset($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'], "multipart/form-data; boundary=") === false) {
      // send json error response using function
      sendResponse(400, false, "Content Type header not set to multipart/form-data with a boundary");
    }

    // check if task belongs to user
    // ADD AUTH TO QUERY
    $query = $readDB->prepare('SELECT id from tbltasks where id = :taskid and userid = :userid');
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    // if no task found then send response back
    if($rowCount === 0) {
      // send json error response using function
      sendResponse(404, false, "Task not found");
    }

    // check to make sure the attributes field has been provided in the request body
    if(!isset($_POST['attributes'])) {
      // send json error response using function
      sendResponse(400, false, "Attributes missing from body of request");
    }

    // check to see if value if attributes is JSON
    if(!$jsonImageAttributes = json_decode($_POST['attributes'])) {
      // send json error response using function
      sendResponse(400, false, "Attributes field is not valid JSON");
    }

    // check to make sure title and filename attributes are provided in the JSON
    if(!isset($jsonImageAttributes->title) || !isset($jsonImageAttributes->filename) || $jsonImageAttributes->title == '' || $jsonImageAttributes->filename == '') {
      // send json error response using function
      sendResponse(400, false, "Title and Filename fields are mandatory");
    }

    // check to make sure that the filename doesnt contain a file extension as it should be the name only
    if(strpos($jsonImageAttributes->filename, ".") > 0) {
      // send json error response using function
      sendResponse(400, false, "Filename must not contain a file extension");
    }

    // check to make sure the imagefile field has been provided in the request body
    // and that the file has uploaded ok (error of 0 means OK)
    if(!isset($_FILES['imagefile']) || $_FILES['imagefile']['error'] !== 0) {
      // send json error response using function
      sendResponse(500, false, "Image file upload unsuccessful - make sure you have selected a file");
    }

    // perform a check on the file uploaded to see if it is a valid image file
    // getimagesize will return false if file is not a valid image file
    $imageFileDetails = getimagesize($_FILES['imagefile']["tmp_name"]);

    // make sure the file is an image file, otherwise return an error response
    if($imageFileDetails == false) {
      // send json error response using function
      sendResponse(400, false, "Not a valid image file");
    }

    // set a sensible upload file size - 5MB in this case (number is in bytes)
    // make sure the php.ini file has a upload file size equal to or greater than this number
    if(isset($_FILES['imagefile']['size']) && $_FILES['imagefile']['size'] > 5242880) {
      // send json error response using function
      sendResponse(400, false, "File must be under 5MB");
    }

    // this is where we define the allowed mimetypes of images uploaded, this will allow jpeg/jpg, gif
    // and png files to be uploaded
    $allowedImageFileTypes = array('image/jpeg', 'image/gif', 'image/png');

    // check if uploaded file mime type is in allowed types, if not send an error response
    if(!in_array($imageFileDetails['mime'], $allowedImageFileTypes)) {
      // send json error response using function
      sendResponse(400, false, "File type not supported");
    }

    // set file extension as blank temporarily
    $fileExtension = "";

    // switch file extension for found mimetype
    switch ($imageFileDetails['mime']) {
      case "image/jpeg":
          $fileExtension = ".jpg";
          break;
      case "image/gif":
          $fileExtension = ".gif";
          break;
      case "image/png":
          $fileExtension = ".png";
          break;
      default:
          break;
    }

    // make sure the file has a file extension
    if($fileExtension == ""){
      // send json error response using function
      sendResponse(400, false, "No valid file extension found from mimetype");
    }

    // after validation checks then move uploaded file to the correct folder
    // and name it with the name provided in the provided attributes
    $image = new Image(null, $jsonImageAttributes->title, $jsonImageAttributes->filename.$fileExtension, $imageFileDetails['mime'], $taskid);

    // get title from image
    $title = $image->getTitle();

    // get filename from image
    $newFileName = $image->getFilename();

    // get mimetype from image
    $mimetype = $image->getMimetype();

    // check if filename already exits for any image for this task
    $query = $readDB->prepare('SELECT tblimages.id from tblimages, tbltasks where tblimages.taskid = tbltasks.id and tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.filename = :filename');
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->bindParam(':filename', $newFileName, PDO::PARAM_STR);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    // if an image with the same filename is found then send error response back
    if($rowCount !== 0) {
      // dont worry about the uploaded temp file from server - it is automatically deleted by php when the script finishes
      // send json error response using function
      sendResponse(409, false, "A file with that filename already exists for this task - try a different filename");
    }

    // create db query to insert image attributes into the images table - use transaction as if the save file
    // function fails then we want to roll back the insert
    $writeDB->beginTransaction();
    $query = $writeDB->prepare('insert into tblimages (title, filename, mimetype, taskid) values (:title, :filename, :mimetype, :taskid)');
    $query->bindParam(':title', $title, PDO::PARAM_STR);
    $query->bindParam(':filename', $newFileName, PDO::PARAM_STR);
    $query->bindParam(':mimetype', $mimetype, PDO::PARAM_STR);
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    // check if row was actually inserted, PDO exception should have caught it if not.
    if($rowCount === 0) {
      // only if writeDB connection has an open transaction we need to roll it back
      if($writeDB->inTransaction()) {
        $writeDB->rollBack();
      }
      // send json error response using function
      sendResponse(500, false, "Failed to upload image");
    }

    // get last task id so we can return the Image attributes in the json
    $lastImageID = $writeDB->lastInsertId();

    $query = $writeDB->prepare('SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
    $query->bindParam(':imageid', $lastImageID, PDO::PARAM_INT);
    $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $query->execute();

    // get row count
    $rowCount = $query->rowCount();

    // make sure that the new image was returned
    if($rowCount === 0) {
      if($writeDB->inTransaction()) {
        $writeDB->rollBack();
      }
      // send json error response using function
      sendResponse(500, false, "Failed to retrieve image attributes after upload - try uploading the image again");
    }

    // create image array to store returned image
    $imageArray = array();

    // for each row returned
    while($row = $query->fetch(PDO::FETCH_ASSOC)) {
      // create new image object for each row
      $image = new Image($row['id'], $row['title'], $row['filename'], $row['mimetype'], $row['taskid']);

      // create image and store in array for return in json data
      $imageArray[] = $image->returnImageAsArray();
    }

    // move uploaded image to the correct location on the filesystem
    $image->saveImageFile($_FILES['imagefile']['tmp_name']);

    // once file moved to correct location then commit image to database
    // if something failed then it should be caught in the ImageException handler below and roll back the DB transaction
    $writeDB->commit();

    // send json success response using function
    sendResponse(201, true, "Image uploaded successfully", false, $imageArray);

  }
  catch(PDOException $ex) {
    error_log("Database Query Error: ".$ex, 0);
    // only if writeDB connection has an open transaction we need to roll it back
    if($writeDB->inTransaction()){
      $writeDB->rollBack();
    }
    // send json error response using function
    sendResponse(500, false, "Failed to upload image");
    exit;
  }
  // if error with image deletion return as json error
  catch(ImageException $ex) {
    // only if writeDB connection has an open transaction we need to roll it back
    if($writeDB->inTransaction()){
      $writeDB->rollBack();
    }
    // send json error response using function
    sendResponse(500, false, $ex->getMessage());
  }
}

// ------------- END FUNCTIONS -----------------


// attempt to set up connections to read and write db connections
try {
  $writeDB = DB::connectWriteDB();
  $readDB = DB::connectReadDB();
}
catch(PDOException $ex) {
  // log connection error for troubleshooting and return a json error response
  error_log("Connection Error: ".$ex, 0);
  // send json error response using function
  sendResponse(500, false, "Database connection error");
}

// check auth status by calling the function and return user id if successful
// if not successful then the exceptions should have been raised and sent a standard error response back
$returned_userid = checkAuthStatusAndReturnUserID($writeDB);

// within this if/elseif statement, it is important to get the correct order (if query string GET param is used in multiple routes)

// check if imageid, task id and attributes is in the url e.g. /tasks/1/images/5/attributes - get or update image attributes
if(array_key_exists("taskid",$_GET) && array_key_exists("imageid",$_GET) && array_key_exists("attributes",$_GET)) {

  // get task id from query string
  $taskid = $_GET['taskid'];

  // get image id from query string
  $imageid = $_GET['imageid'];

  // get attributesfrom query string
  $attributes = $_GET['attributes'];

  //check to see if image id/taskid in query string is not empty and is number, if not return json error
  if($imageid == '' || !is_numeric($imageid) || $taskid == '' || !is_numeric($taskid)) {
    // send json error response using function
    sendResponse(400, false, "Image ID or Task ID cannot be blank or must be numeric");
  }

  // if request is a GET, e.g. get image attribute - /v1/tasks/1/images/5/attributes
  if($_SERVER['REQUEST_METHOD'] === 'GET') {

    // call getImageAttributesRoute function - passing in taskid and imageid
    getImageAttributesRoute($readDB, $taskid, $imageid, $returned_userid);
  }
  // if request is a PATCH, e.g. update only image attribute and not image file - /v1/tasks/1/images/5/attributes
  elseif($_SERVER['REQUEST_METHOD'] === 'PATCH') {

    // call updateImageAttributesRoute function - passing in taskid and imageid
    updateImageAttributesRoute($writeDB, $taskid, $imageid, $returned_userid);
  }
  // if any other request method apart from GET is used then return 405 method not allowed
  else {
    // send json error response using function
    sendResponse(405, false, "Request method not allowed");
  }
}
// check if imageid and task id is in the url e.g. /tasks/1/images/5 - get image
elseif(array_key_exists("taskid",$_GET) && array_key_exists("imageid",$_GET)) {

  // get task id from query string
  $taskid = $_GET['taskid'];

  // get image id from query string
  $imageid = $_GET['imageid'];

  //check to see if image id/taskid in query string is not empty and is number, if not return json error
  if($imageid == '' || !is_numeric($imageid) || $taskid == '' || !is_numeric($taskid)) {
    // send json error response using function
    sendResponse(400, false, "Image ID or Task ID cannot be blank or must be numeric");
  }

  // if request is a GET, e.g. get image - /v1/tasks/1/images/5
  if($_SERVER['REQUEST_METHOD'] === 'GET') {

    // call getImageRoute function - passing in taskid and imageid
    getImageRoute($readDB, $taskid, $imageid, $returned_userid);
  }
  // if requets is a DELETE, e.g. delete task image
  elseif($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    // call deleteImageRoute function - passing in taskid and imageid
    deleteImageRoute($writeDB, $taskid, $imageid, $returned_userid);
  }
  // if any other request method apart from GET and DELETE is used then return 405 method not allowed
  else {
    // send json error response using function
    sendResponse(405, false, "Request method not allowed");
  }
}
// handle creating new task image
elseif(array_key_exists("taskid",$_GET) && !array_key_exists("imageid",$_GET)) {

  // get task id from query string
  $taskid = $_GET['taskid'];

  //check to see if taskid in query string is not empty and is number, if not return json error
  if($taskid == '' || !is_numeric($taskid)) {
    // send json error response using function
    sendResponse(400, false, "Task ID cannot be blank or must be numeric");
  }

  // if request is a POST e.g. create task image
  if($_SERVER['REQUEST_METHOD'] === 'POST') {

    // call uploadImageRoute function
    uploadImageRoute($readDB, $writeDB, $taskid, $returned_userid);
  }
  // if any other request method apart from POST is used then return 405 method not allowed
  else {
    // send json error response using function
    sendResponse(405, false, "Request method not allowed");
  }
}
// return 404 error if endpoint not available
else {
  // send json error response using function
  sendResponse(404, false, "Endpoint not found");
}
