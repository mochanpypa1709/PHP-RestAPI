<?php

require_once('db.php');
require_once('../model/Task.php');
require_once('../model/Response.php');

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();
}
catch (PDOException $ex) {
    error_log("Connection error - ".$ex, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connection error");
    $response->send();
    exit();
}

if(array_key_exists("taskid",$_GET)) {
    $taskid = $_GET['taskid'];

    if($taskid == '' || !is_numeric($taskid)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Task ID cannot be blank or must Response connection error");
        $response->send();
        exit();
    }

    if($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed from tbltasks where id = :taskid');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit();
            }
            while($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $taskArray[] = $task->returnTaskAsArray();
            }
            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();
        }
        catch(TaskException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit();
        }
        catch(PDOException $ex) {
            error_log("Database query error - ".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Task");
            $response->send();
            exit();
        }
    }
    elseif($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {
            $query = $writeDB->prepare('delete from tbltasks where id = :taskid');
            $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
            $query->execute();

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
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task deleted");
            $response->send();
            exit;
        }
        catch(PDOException $ex) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to delete task");
            $response->send();
            exit;
        }
    }
    elseif($_SERVER['REQUEST_METHOD'] === 'PATCH') {

    }
    else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit();
    }
}
elseif(array_key_exists("completed",$_GET)) {
    
}