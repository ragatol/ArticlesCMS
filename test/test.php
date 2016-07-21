<?php
include "../articles.php";

@unlink("test.db"); // for testing
$db = new Articles\DataBase(file_get_contents("test.json"));
// list root categories
$db->setLanguage("en");
echo "Listing database hierarchy (english):\n";
foreach ($db->listCategories() as $cat) {
	echo "Category $cat->id: $cat->name\n";
	foreach ($cat->listSubCategories() as $subcat) {
		echo "+ Sub-Category $subcat->id: $subcat->name\n";
		foreach ($subcat->listArticles() as $article) {
			echo "\t- $article->title: $article->description; ($article->id)\n";
		}
	}
	foreach ($cat->listArticles() as $article) {
		echo "- $article->title: $article->description; ($article->id)\n";
	}
}

$db->setLanguage("pt");
echo "\nListing database hierarchy (portuguese):\n";
foreach ($db->listCategories() as $cat) {
	echo "Category $cat->id: $cat->name\n";
	foreach ($cat->listSubCategories() as $subcat) {
		echo "+ Sub-Category $subcat->id: $subcat->name\n";
		foreach ($subcat->listArticles() as $article) {
			echo "\t- $article->title: $article->description; ($article->id)\n";
		}
	}
	foreach ($cat->listArticles() as $article) {
		echo "- $article->title: $article->description; ($article->id)\n";
	}
}