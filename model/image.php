<?php
// Image Model Object

// empty ImageException class so we can catch image errors
class ImageException extends Exception { }

class Image {
	// define private variables
	// define variable to store image id number
	private $_id;
	// define variable to store image title
	private $_title;
	// define variable to store image filename
	private $_filename;
	// define variable to store image mimetype
	private $_mimetype;
	// define variable to store task id in which the image is associated with
	private $_taskid;
	// define variable to hold file location where we are going to upload/retrieve images from
	private $_uploadFolderLocation;


  // constructor to create the image object with the instance variables already set
	public function __construct($id, $title, $filename, $mimetype, $taskid) {
		$this->setID($id);
		$this->setTitle($title);
		$this->setFilename($filename);
		$this->setMimetype($mimetype);
		$this->setTaskID($taskid);
		// define it once and use it multiple times - only one place to change if we need to update in the future
		$this->_uploadFolderLocation = "../../../taskimages/";
	}

  // function to return image ID
	public function getID() {
		return $this->_id;
	}

  // function to return image title
	public function getTitle() {
		return $this->_title;
	}

  // function to return image filename
	public function getFilename() {
		return $this->_filename;
	}

	// function to return image filename extension from filename
	public function getFileExtension() {
		// split filename into array parts by dot
		$filenameParts = explode(".", $this->_filename);

		// make sure the filename contains an extension - if not then throw and image exceptioj
		if(!$filenameParts) {
			throw new ImageException("Filename does not contain a file extension");
		}
		// get last element number from array as this will contain the file extension
		$lastArrayElement = count($filenameParts)-1;
		// get last element from array
		$fileExtension = $filenameParts[$lastArrayElement];
		// return file extension
		return $fileExtension;
	}

	// function to return image mimetype
	public function getMimetype() {
		return $this->_mimetype;
	}

  // function to return task id associated with the image
	public function getTaskID() {
		return $this->_taskid;
	}

	// function to return upload folder location
	public function getUploadFolderLocation() {
		return $this->_uploadFolderLocation;
	}

	// function to return image file to the client
	public function returnImageFile() {

		// file path and name
		$filepath = $this->getUploadFolderLocation().$this->getTaskID().'/'.$this->getFilename();

		// check if file exists
		if(!file_exists($filepath)){
			throw new ImageException("Image file not found");
		}

    	header('Content-Type: '.$this->getMimetype());
		// set default name of file if client downloads it
		header('Content-Disposition: inline; filename="'.$this->getFilename().'"');

		// check to see if file was successfully read
    	if(!readfile($filepath)){
			// set http status code in response header for 404 not found if image cannot be read
			// cannot send back json at this point as we have set the content type header
			http_response_code(404);
			exit;
		}
		// stop further processing of script as we have pushed the image out.
		exit;
	}


	// function to set the private image ID
	public function setID($id) {
		// if passed in image ID is not null or not numeric, is not between 0 and 9223372036854775807 (signed bigint max val - 64bit)
		// over nine quintillion rows
		if(($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)) {
			throw new ImageException("Image ID error");
		}
		$this->_id = $id;
	}

  // function to set the private image title
	public function setTitle($title) {
		// if passed in title is not between 1 and 255 characters
		if(strlen($title) < 1 || strlen($title) > 255) {
			throw new ImageException("Image title error");
		}
		$this->_title = $title;
	}

  // function to set the private image filename
	public function setFilename($filename) {
		// if passed in filename is not between 1 and 30 characters
		// only contain valid characters for file names a-zA-Z0-9_- (and have only a jpg, gif or png file extension)
		if(strlen($filename) < 1 || strlen($filename) > 30 || preg_match("/^[a-zA-Z0-9_-]+(.jpg|.gif|.png)$/", $filename) != 1) {
			throw new ImageException("Image filename error - must be between 1 and 30 characters long and only contain alphanumeric, underscore, hyphen, no spaces and have a .jpg, .gif or a .png file extension");
		}
		$this->_filename = $filename;
	}

	// function to set the private image mimetype
	public function setMimetype($mimetype) {
		// if passed in mimetype is not between 1 and 255 characters as mimetype is max 255 chars
		if(strlen($mimetype) < 1 || strlen($mimetype) > 255) {
			throw new ImageException("Image mimetype error");
		}
		$this->_mimetype = $mimetype;
	}

	// function to set the image task ID
	public function setTaskID($taskid) {
		// if passed in image task ID is not null or not numeric, is not between 0 and 9223372036854775807 (signed bigint max val - 64bit)
		// over nine quintillion rows
		if(($taskid !== null) && (!is_numeric($taskid) || $taskid <= 0 || $taskid > 9223372036854775807 || $this->_taskid !== null)) {
			throw new ImageException("Image Task ID error");
		}
		$this->_taskid = $taskid;
	}

	// function to delete physical file
	public function deleteImageFile() {

		// file path and name
		$filepath = $this->getUploadFolderLocation().$this->getTaskID().'/'.$this->getFilename();

		// check if file exists - if it doesnt then dont do anything as no file is there to delete
		if(file_exists($filepath)){
			// delete file if it exists - if failed to delete then through an image exception
			if(!unlink($filepath)) {
				throw new ImageException("Failed to delete image file");
			}
		}
	}

	// function to save file
	public function saveImageFile($tempFileName) {

		// file path and name
		$uploadedFilePath = $this->getUploadFolderLocation().$this->getTaskID().'/'.$this->getFilename();

		// if task id folder doesnt exist then create the folder
		if(!is_dir($this->getUploadFolderLocation().$this->getTaskID())) {
			// check to see if folder was created - if not then send error response
    	if(!mkdir($this->getUploadFolderLocation().$this->getTaskID())) {
				throw new ImageException("Failed to create image upload folder for task");
			}
		}
		// check if temporary file exists - if it doesnt then dont do anything as no file is there to move
		if(!file_exists($tempFileName)){
			throw new ImageException("Failed to upload image file");
		}

		// move the file to it's correct location if it exists - if failed to move it then through an image exception
		if(!move_uploaded_file($tempFileName, $uploadedFilePath)) {
	      throw new ImageException("Failed to upload image file");
	  }
	}

	// function to rename file
	public function renameImageFile($oldFileName, $newFilename) {

		// file path and name
		$originalFilePath = $this->getUploadFolderLocation().$this->getTaskID().'/'.$oldFileName;
		// new file path and name
		$renamedFilePath = $this->getUploadFolderLocation().$this->getTaskID().'/'.$newFilename;

		// make sure original file exists
		if(!file_exists($originalFilePath)){
			throw new ImageException("Cannot find image file to rename");
		}

		// rename the file to it's new name - if failed to rename it then through an image exception
		if(!rename($originalFilePath, $renamedFilePath)) {
	      throw new ImageException("Failed to update filename");
	  }
	}

	// get the url to be used to return the actual image
	public function getImageURL() {
		// check if http or https is being used in order to build up the url
		$httpOrHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
		// get the hostname / domain name for current url
		$host = $_SERVER['HTTP_HOST'];
		// link url to navigate to image
		$url = "/v1/tasks/".$this->getTaskID()."/images/".$this->getID();
		// return the built up url to access the image directly
		return $httpOrHttps."://".$host.$url;
	}


  // function to return image object as an array for json
	public function returnImageAsArray() {
		$image = array();
		$image['id'] = $this->getID();
		$image['title'] = $this->getTitle();
		$image['filename'] = $this->getFilename();
		$image['mimetype'] = $this->getMimetype();
		$image['taskid'] = $this->getTaskID();
		$image['imageurl'] = $this->getImageURL();
		return $image;
	}

}
