<?php
$_SESSION = [];
session_destroy();
redirect(url('login'));
