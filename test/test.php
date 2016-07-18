<?php
include "../articles.php";

@unlink("test.db"); // for testing
$db = new Articles\DataBase(file_get_contents("test.json"));
// list root categories
$db->setLanguage("en");
foreach ($db->listCategories() as $cat) {
	echo "Categoria $cat->id: $cat->name\n";
	foreach ($cat->listSubCategories() as $subcat) {
		echo "Sub-Categoria $subcat->id: $subcat->name\n";
	}
}