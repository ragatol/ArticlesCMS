<?php

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
	public $identifier;
	private $category;
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

	public function getCategory() {
		return $this->database->category($this->category);
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
	public $identifier;
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
	public function subCategories() {
		return $this->database->categories($this->id);
	}

	/**
	 * Returns a Generator that returns Article objects from this Category
	 * @param string $order how to sort the articles (not safe for end user interface)
	 * @param int $limit how much articles to fetch
	 * @param int $start offset of the results
	 * @return \Generator
	 */
	public function articles( $order = NULL, int $limit = 0, int $start = 0) {
		$pdo = $this->database->PDO();
		$lang = $this->database->getLanguage();
		if ($order !== NULL) $order = "ORDER BY ".$order;
		$strlimit = "";
		if ($limit > 0) $strlimit .= "LIMIT $limit ";
		if ($start > 0) $strlimit .= "OFFSET $start";
		$res = $pdo->query("SELECT * FROM articles WHERE lang == \"$lang\" AND category == $this->id $order $strlimit;");
		if ($res === FALSE) return;
		while ($o = $res->fetchObject("Articles\Article",[$this->database])) {
			yield $o;
		}
	}

	/**
	 * Returns a Generator that returns Article objects from this Category and all Sub-Categories
	 * @param string $order how to sort the articles (not safe for end user interface)
	 * @param int $limit how much articles to fetch
	 * @param int $start offset of the results (discard the first $start objects)
	 * @return \Generator
	 */
	public function articlesRecursive( $order = NULL, int $limit = 0, int $start = 0) {
		$pdo = $this->database->PDO();
		$lang = $this->database->getLanguage();
		if ($order !== NULL) $order = "ORDER BY ".$order;
		$strlimit = "";
		if ($limit > 0) $strlimit .= "LIMIT $limit ";
		if ($start > 0) $strlimit .= "OFFSET $start";
		$res = $pdo->query("WITH RECURSIVE pcat(category) AS (
				VALUES($this->id)
				UNION ALL SELECT ci.id from categories_indexes ci, pcat
				WHERE ci.parent = pcat.category LIMIT 100
			)
			SELECT * FROM articles WHERE lang == \"$lang\" AND category IN pcat $order $strlimit;");
		if ($res === FALSE) return;
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
			"identifier" TEXT,
			"path" TEXT);');
		$db->exec('CREATE TABLE IF NOT EXISTS "categories_data" (
			"id" INTEGER REFERENCES categories_indexes(id),
			"lang" TEXT,
			"name" TEXT)');
		$db->exec('CREATE VIEW "categories" AS
			SELECT categories_indexes.id, parent, identifier, categories_data.lang, categories_data.name
			from categories_indexes, categories_data
			where categories_data.id == categories_indexes.id;');
		// article data
		$db->exec('CREATE TABLE IF NOT EXISTS "articles_indexes" (
			"id" INTEGER PRIMARY KEY AUTOINCREMENT,
			"category" INTEGER REFERENCES categories_indexes(id),
			"identifier" TEXT,
			"author" TEXT,
			"published" DATETIME,
			"edited" DATETIME,
			"path" TEXT)');
		$db->exec('CREATE TABLE IF NOT EXISTS "articles_data" (
			"id" INTEGER REFERENCES articles_indexes(id),
			"lang" TEXT,
			"title" TEXT,
			"description" TEXT,
			"keywords" TEXT,
			"file" TEXT)');
		$db->exec('CREATE VIEW "articles" AS
			SELECT articles_indexes.id, identifier, category, lang, title, description, author, published, edited, path, file, keywords
			FROM articles_indexes, articles_data
			WHERE articles_data.id == articles_indexes.id;');
	}

	function makeArticle( string $path, int $category ) : int {
		$info = \json_decode(\file_get_contents($path."article.json"));
		$identifier = $info->id ?? \basename($path);
		// verify edited and published datetimes
		if (!isset($info->edited)) $info->edited = date(DATE_ATOM);
		if (!isset($info->published)) $info->published = date(DATE_ATOM);
		$this->connection->exec("INSERT INTO 'articles_indexes' (identifier, category, author, edited, published, path)
				 VALUES (\"$identifier\",$category,'$info->author','$info->edited','$info->published','$path');");
		$id = $this->connection->lastInsertId();
		// add language dependent info
		$sql = $this->connection->prepare("INSERT INTO 'articles_data' (id, lang, title, description, keywords, file)
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
		$identifier = $info->id ?? \basename($path);
		$this->connection->exec("INSERT INTO \"categories_indexes\" (parent,identifier,path) VALUES ($parent,\"$identifier\",\"$path\");");
		$id = $this->connection->lastInsertId();
		$ins = $this->connection->prepare("INSERT INTO 'categories_data' (id,lang,name) values ($id,:language,:name);");
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
	public function categories(int $parent_category = 0) {
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
	public function newCategory( string $dirname, int $parent = 0 ) {
		return NULL;
	}

	public function category( int $cat_id ) {
		$res = $this->connection->query("SELECT * FROM categories WHERE id == $cat_id AND lang == \"$this->language\";");
		return $res->fetchObject("Articles\Category",[$this]);
	}

	public function categoryByIdentifier( string $cat_identifier ) {
		$res = $this->connection->prepare("SELECT * FROM categories WHERE identifier == :identifier AND lang == :language;");
		$res->bindParam(':identifier',$cat_identifier);
		$res->bindParam(':language',$this->language);
		$res->execute();
		return $res->fetchObject("Articles\Category",[$this]);
	}

	public function article( int $article_id ) {
		$res = $this->connection->query("SELECT id, title, author, description, category, lang, file, keywords, published, edited
				FROM articles WHERE id == $article_id AND lang == \"$this->language\";");
		return $res->fetchObject("Articles\Article",[$this]);
	}

	public function articleByIdentifier( string $identifier ) {
		$res = $this->connection->prepare("SELECT * FROM articles WHERE identifier = :identifier AND lang = :language;");
		$res->bindParam(':identifier',$identifier);
		$res->bindParam(':language',$this->language);
		$res->execute();
		return $res->fetchObject("Articles\Article",[$this]);
	}

	public function PDO() : \PDO {
		return $this->connection;
	}

}