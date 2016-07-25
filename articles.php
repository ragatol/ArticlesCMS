<?php
/*
Articles - A Simple Content Management System for Personal Websites
Requires PHP Version 7 or higher.

BSD 3-Clause Licence:

Copyright (c) 2016, Rafael Fernandes
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice,
   this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its contributors
   may be used to endorse or promote products derived from this software without
   specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY
EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES 
OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT 
SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY pathECT, INpathECT, INCIDENTAL, 
SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT 
OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) 
HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR 
TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, 
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

namespace Articles;

/*
Articles at the base directory of site data don't have a category.
Article is a folder with an article.json definition file and other content used by the article

Article definition file (article.json):

{
	"languages" = {
		"en" = {
			"title" = "English article Title",
			"description" = "Short description (english)",
			"file" = "English article file",
			"keywords" = "comma,separated,keywords" // optional
		},
		"pt" = {
			"title" =  "Portuguese article title",
			"description" = "Short description (portuguese)",
			"file" = "Portuguese article file",
		},
		etc...
	}
	"published" = "mm-dd-yyyy date to publish" // optional
	"editeded" = "mm-dd-yyyy date of last edit" // optional
	"author" = "Article author"
}
*/

class Article {

	private $database;

	public $id;
	public $category;
	public $lang;
	public $title;
	public $author;
	public $description;
	public $file;
	public $keywords;
	public $published;
	public $edited;



	public function __construct(DataBase $db) {
		$this->database = $db;
	}

}

/*
Category definition:
Organize articles inside distict categories, using filesystem folders.

A folder that represents a category must have the category.json file, with the following information

{
	"languages" = {
		"en" = "English category name",
		"pt" = "Portuguese categoy name",
		etc...
	}
	
}

*/

class Category {

	private $database;
	public $id;
	public $parent;
	public $lang;
	public $name;

	public function __construct(DataBase $db) {
		$this->database = $db;
	}
	
	/**
	 * Returns a Generator that generates Category objects from categories that are immediate children of this category.
	 * @param string $language language to get the 'name' property from
	 * @return \Generator
	 */
	public function listSubCategories() : \Generator {
		return $this->database->listCategories($this->id);
	}

	/**
	 * Returns a Generator that returns Article objects from this Category
	 * @param string $sortby how to sort the articles
	 * @param string $limit how much articles to fetch
	 * @return \Generator
	 */
	public function listArticles( $sortby = '', int $limit = -1, int $start = -1) : \Generator {
		$pdo = $this->database->PDO();
		$lang = $this->database->getLanguage();
		if ($sortby != '') $sortby = "SORT BY ".$pdo->$sortby;
		$strlimit = "";
		if ($limit > -1) $strlimit .= "LIMIT $limit ";
		if ($start > -1) $strlimit .= "OFFSET $start";
		$res = $pdo->query("SELECT id, title, author, category, description, lang, file, keywords, published, edited
				FROM articles WHERE lang == \"$lang\" AND category == $this->id $sortby $strlimit;");
		while ($o = $res->fetchObject("Articles\Article",[$this->database])) {
			yield $o;
		}
	}

}

/*
Articles DataBase configuration:
json file with the following attributes:

"server" : PDO UDN string for the first parameter, eg. 'sqlite:articles.db'
"username" : database connection username
"password" : database connection password
"basepath" : content base directory (full path or reative to site base directory, needs read/write permissions)
*/

/**
 * Articles DataBase access
 * @author rafael
 *
 */
class DataBase {

	private $connection;
	public $basedir;
	public $defaultCaching;
	private $language;

	public function __construct( string $config, string $language = "en" ) {
		$cfg = \json_decode(\file_get_contents($config));
		$this->basepath = $cfg->basedir;
		$this->language = $language;
		$this->connection = new \PDO($cfg->server,$cfg->username,$cfg->password);
		// check if base tables exists, will return error code '00000' if ok
		$this->connection->exec('SELECT 1 FROM categories LIMIT 1;');
		if ($this->connection->errorCode() != "00000") {
			$this->createTables();
			$this->connection->beginTransaction();
			$this->addFolder($this->basepath);
			$this->connection->commit();
		}
	}

	/**
	* Create base tables for Articles
	*/
	function createTables() {
		$db = $this->connection;
		// category data
		$db->exec('CREATE TABLE IF NOT EXISTS "categories_indexes" (
			"id" INTEGER PRIMARY KEY AUTOINCREMENT,
			"parent" INTEGER REFERENCES categories_indexes,
			"path" TEXT);');
		$db->exec('CREATE TABLE IF NOT EXISTS "categories_data" (
			"cat" INTEGER REFERENCES categories_indexes(id),
			"lang" TEXT,
			"name" TEXT)');
		$db->exec('CREATE VIEW "categories" AS
			SELECT id, parent, categories_data.lang, categories_data.name
			from categories_indexes, categories_data
			where categories_data.cat == categories_indexes.id;');
		// article data
		$db->exec('CREATE TABLE IF NOT EXISTS "articles_indexes" (
			"id" INTEGER PRIMARY KEY AUTOINCREMENT,
			"category" INTEGER REFERENCES categories_indexes(id),
			"author" TEXT,
			"published" DATETIME,
			"edited" DATETIME,
			"path" TEXT)');
		$db->exec('CREATE TABLE IF NOT EXISTS "articles_data" (
			"article" INTEGER REFERENCES articles_indexes(id),
			"lang" TEXT,
			"title" TEXT,
			"description" TEXT,
			"keywords" TEXT,
			"file" TEXT)');
		$db->exec('CREATE VIEW "articles" AS
			SELECT id, category, lang, title, description, author, published, edited, path, file, keywords
			FROM articles_indexes, articles_data
			WHERE articles_data.article == articles_indexes.id;');
	}

	function makeArticle( string $path, int $category ) : int {
		$info = \json_decode(\file_get_contents($path."article.json"));
		// verify edited and published datetimes
		if (!isset($info->edited)) $info->edited = date(DATE_ATOM);
		if (!isset($info->published)) $info->published = date(DATE_ATOM);
		$this->connection->exec("INSERT INTO 'articles_indexes' (category, author, edited, published, path)
				 VALUES ($category,'$info->author','$info->edited','$info->published','$path');");
		$id = $this->connection->lastInsertId();
		// add language dependent info
		$sql = $this->connection->prepare("INSERT INTO 'articles_data' (article, lang, title, description, keywords, file)
				VALUES ($id, :lang, :title, :description, :keywords, :file);");
		foreach ($info->languages as $lang => $data) {
			if (!isset($data->keywords)) $data->keywords = "";
			$sql->bindParam(':lang', $lang);
			$sql->bindParam(':title', $data->title);
			$sql->bindParam(':description', $data->description);
			$sql->bindParam(':keywords', $data->keywords);
			$sql->bindValue(':file', $path.$data->file);
			$sql->execute();
		}
		return $id;
	}

	function makeCategory( string $path, int $parent ) : int {
		$info = \json_decode(\file_get_contents($path."category.json"));
		$this->connection->exec("INSERT INTO \"categories_indexes\" (parent,path) VALUES ($parent,\"$path\");");
		$id = $this->connection->lastInsertId();
		$ins = $this->connection->prepare("INSERT INTO 'categories_data' (cat,lang,name) values ($id,:language,:name);");
		foreach ($info as $lang => $name) {
			$ins->bindParam(':language',$lang);
			$ins->bindParam(':name',$name);
			$ins->execute();
		}
		return $id;
	}

	public function addFolder( string $path, int $parent = 0) {
		// check for article.json (json)
		if (file_exists($path."article.json")) {
			// directory is an article
			$this->makeArticle($path,$parent);
			return;
		}
		if (file_exists($path."category.json")) {
			// directory is a category
			$parent = $this->makeCategory($path,$parent);
		}
		// directory is category or neither, check directories inside
		$dir = opendir($path);
		if ($dir == FALSE) return;
		while (false !== ($entry = readdir($dir))) {
			if ($entry == "." || $entry == "..") continue;
			$direc = $path.$entry."/";
			if (!is_dir($direc)) continue;
			$this->addFolder($direc,$parent);
		}
	}
	
	public function setLanguage( string $language ) {
		$this->language = $language;
	}
	
	public function getLanguage() {
		return $this->language;
	}
	
	/**
	 * Returns a generator that genereates Category objects.
	 * @param string $language
	 * @return \Generator
	 */
	public function listCategories(int $parent_category = 0) : \Generator {
		$res = $this->connection->query("SELECT * FROM categories WHERE lang == \"$this->language\" AND parent == $parent_category;");
		while ($cat = $res->fetchObject("Articles\Category",[$this])) {
			yield $cat;
		}
	}
	
	/**
	 * Creates and prerare a directory as a Category
	 * @param string $dirname folder name to use (will be created inside proper directory hierarchy)
	 * @param int $parent parent Category
	 * @return Category
	 */
	public function newCategory( string $dirname, int $parent = 0 ) : Category {
		return NULL;
	}

	public function getCategory( int $cat_id ) : Category {
		$res = $this->connection->query("SELECT * FROM categories WHERE id == $cat_id AND lang == \"$this->language\";");
		if ($res->rowCount() > 0) return $res->fetchObject("Articles\Category",[$this]);
		return NULL;
	}

	public function getArticle( int $article_id ) : Article {
		$res = $this->connection->query("SELECT id, title, author, description, category, lang, file, keywords, published, edited
				FROM articles WHERE id == $article_id AND lang == \"$this->language\";");
		if ($res->rowCount() > 0) return $res->fetchObject("Articles\Article",[$this]);
		return NULL;
	}

	public function PDO() : \PDO {
		return $this->connection;
	}

}