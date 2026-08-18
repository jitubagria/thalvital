<?php require_once __DIR__.'/../includes/auth.php';if(staff())audit('staff_logout','staff',staff()['id']);$_SESSION=[];session_destroy();header('Location: /staff/login.php');
