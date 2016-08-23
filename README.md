# ArticlesCMS #

ArticlesCMS is a multi-language content management system for personal websites or blogs,
focused on simplicity and ease of use alongside with the developer's own PHP code. 

## Why another CMS?

ArticlesCMS was created to fulfill the following needs I consider essential for my projects:

-	Ease to use for quick prototyping;
-	Fast, low resource use;
-	Simple, transparent selection of content based on visitor's language; 

Most current CMSs are "feature complete" and monolithic, and don't allow easy use of their code alongsite my own code,
are complicated and use a lot of resources even for the simplest sites/blogs.
In addition, most CMSs don't handle multi-language content very well, and since I wanted to make my website accessible for both English and
Portuguese speakers, they hardly fullfill my needs.

## Features

ArticlesCMS is quite simple and is only used as a base structure for organizing your website content.
The main features are:

-	Content organized in a folder structure;
-	Articles and Categories metadata can be read from simple JSON files inside the folder structure,
	allowing for fast content prototyping without the need for a database management system.
-	It's possible to use any PDO compatible database engine; SQLite highly recommended for small sites.
-	Easy listing and filtering of articles/categories using `Generators`.

A page for content management like other CMSs is planned for future development for sites that need an
user-friendly way to manage content.

## Examples

Category listing:
~~~php
include "articles.php";

$db = new Articles\DataBase("site.json");
foreach ($db->categories() as $category) {
	echo $category->name, "\n";
}
~~~

Listing the 10 latest articles from a category:
~~~php
include "articles.php";

$db = new Articles\DataBase("site.json");
$category = $db->categoryByName("tutorials");
foreach ($category->articles("published DESC",10) as $article) {
	echo $article->title, " - ", $article->description, " - ", $article->author, "\n";
}
~~~

## Bugs and Suggestions

You can use the GitHub issues page [here](http://github.com/ragatol/ArticlesCMS/issues).
