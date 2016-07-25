<?php
include "../articles.php";

@unlink("test.db"); // for testing
$db = new Articles\DataBase("test.json");

function makeList($category = 0) { 
	global $db;
	echo "<ul>\n";
	foreach ($db->listCategories($category) as $cat) {
		echo "<li><h3>$cat->name</h3>\n";
		echo "<ul>";
		foreach ($cat->listArticles() as $article) {
			echo "<li><h4>$article->title:</h4> $article->description</li>\n";
		}
		echo "</ul>\n";
		makeList($cat->id);
	}
	echo "</ul>\n";
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>ArticlesCMS Test</title>
</head>
<body>

<h2>Listing database hierarchy (english):</h2>
<?php
$db->setLanguage("en");
makeList();
?>

<h2>Listing database hierarchy (portuguese):</h2>
<?php
$db->setLanguage("pt");
makeList();
?>
</body>
</html>
