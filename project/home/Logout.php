<?php
session_start();
session_unset(); 
session_destroy(); 


header("Location: ../studentV2/index.php");
exit();
?>
