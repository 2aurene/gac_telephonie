<?php
	
	const BASE_NAME = 'gac_telephonie';
	const BASE_USER = 'root';
	const BASE_PASS = '';
	
	$pdo = new PDO('mysql:host=localhost;dbname='.BASE_NAME, BASE_USER, BASE_PASS);