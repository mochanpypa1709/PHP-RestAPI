<?php

require_once('task.php');

try {
    $task = new Task(1, "Title Here", "Description Here", "27/04/2021 12:00", "");
    header('Content-type: application/json;charset=UTF-8');
    echo json_encode($task->returnTaskAsArray());
}
catch(TaskException $ex ) {
    echo "Error: ".$ex->getMessage();
}