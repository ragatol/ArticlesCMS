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
	public $name;
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
	public $name;
	public $lang;
	public $title;

	public function __construct(DataBase $db) {
		$this->database = $db;
	}
	
	/**
	 * Returns a Generator that generates Category objects from categories that are immediate children of this category.
	 * @param string $language language to get the 'name' property from
	 * @return \Generator
	 */
	public function categories() {
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
		$res = $pdo->query("WITH RECURSIVE child_categories(id) AS (
				VALUES($this->id)
				UNION SELECT ci.id from categories_indexes ci, child_categories cc
				WHERE ci.parent = cc.id LIMIT 100
			)
			SELECT * FROM articles WHERE lang == \"$lang\" AND category IN child_categories $order $strlimit;");
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
			"id" INTEGER PRIMARY KEY ASC,
			"parent" INTEGER REFERENCES categories_indexes,
			"name" TEXT UNIQUE NOT NULL,
			"path" TEXT);');
		$db->exec('CREATE TABLE IF NOT EXISTS "categories_data" (
			"id" INTEGER REFERENCES categories_indexes(id),
			"lang" TEXT,
			"title" TEXT)');
		$db->exec('CREATE VIEW "categories" AS
			SELECT ci.id, ci.parent, ci.name, cd.lang, cd.title
			FROM categories_indexes ci, categories_data cd
			WHERE cd.id == ci.id;');
		// article data
		$db->exec('CREATE TABLE IF NOT EXISTS "articles_indexes" (
			"id" INTEGER PRIMARY KEY ASC,
			"category" INTEGER REFERENCES categories_indexes(id),
			"name" TEXT UNIQUE NOT NULL,
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
			SELECT articles_indexes.id, name, category, lang, title, description, author, published, edited, path, file, keywords
			FROM articles_indexes, articles_data
			WHERE articles_data.id == articles_indexes.id;');
		$db->exec('ANALYZE;');
	}

	function makeArticle( string $path, int $category ) : int {
		// basic info
		$info = \json_decode(\file_get_contents($path."/article.json"));
		$name = $info->name ?? \basename($path);
		$edited = $info->edited ?? \date(DATE_ATOM);
		$published = $info->published ?? \date(DATE_ATOM);
		$this->connection->exec("INSERT INTO 'articles_indexes' (name, category, author, edited, published, path)
				 VALUES ('$name',$category,'$info->author','$edited','$published','$path');");
		$id = $this->connection->lastInsertId();
		// language dependent info
		$sql = $this->connection->prepare("INSERT INTO 'articles_data' (id, lang, title, description, keywords, file)
				VALUES ($id, :lang, :title, :description, :keywords, :file);");
		foreach ($info->languages as $lang => $data) {
			$sql->bindParam(':lang', $lang);
			$sql->bindParam(':title', $data->title);
			$sql->bindParam(':description', $data->description);
			$sql->bindValue(':keywords', $data->keywords ?? "");
			$sql->bindValue(':file', $path."/".$data->file);
			$sql->execute();
		}
		return $id;
	}

	function makeCategory( string $path, int $parent ) : int {
		// basic info
		$info = \json_decode(\file_get_contents($path."/category.json"));
		$name = $info->name ?? \basename($path);
		$this->connection->exec("INSERT INTO \"categories_indexes\" (parent,name,path) VALUES ($parent,\"$name\",\"$path\");");
		$id = $this->connection->lastInsertId();
		// language dependent info
		$ins = $this->connection->prepare("INSERT INTO 'categories_data' (id,lang,title) values ($id,:language,:title);");
		foreach ($info as $lang => $title) {
			$ins->bindParam(':language',$lang);
			$ins->bindParam(':title',$title);
			$ins->execute();
		}
		return $id;
	}

	public function addFolder( string $path, int $parent = 0) {
		// check for article.json (json)
		if (\file_exists($path."/article.json")) {
			// directory is an article
			$this->makeArticle($path,$parent);
			return;
		}
		if (\file_exists($path."/category.json")) {
			// directory is a category
			$parent = $this->makeCategory($path,$parent);
		}
		// directory is category or neither, check directories inside
		$dir = new \DirectoryIterator($path);
		foreach ($dir as $fileinfo) {
			if (!$fileinfo->isDot() && $fileinfo->isDir()) {
				$this->addFolder($fileinfo->getPathname(),$parent);
			}
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

	/**
	* Get a Category object from the database
	* @param int $id category database id
	* @return Category
	*/
	public function category( int $id ) {
		$res = $this->connection->query("SELECT * FROM categories WHERE id == $id AND lang == \"$this->language\";");
		return $res->fetchObject("Articles\Category",[$this]);
	}

	/**
	* Get a Category object from the database using the unique 'name'
	* @param string $name unique category name
	* @return Category
	*/
	public function categoryByName( string $name ) {
		$res = $this->connection->prepare("SELECT * FROM categories WHERE name == :name AND lang == :language;");
		$res->bindParam(':name',$name);
		$res->bindParam(':language',$this->language);
		$res->execute();
		return $res->fetchObject("Articles\Category",[$this]);
	}

	public function article( int $id ) {
		$res = $this->connection->query("SELECT * FROM articles WHERE id == $id AND lang == \"$this->language\";");
		return $res->fetchObject("Articles\Article",[$this]);
	}

	public function articleByName( string $name ) {
		$res = $this->connection->prepare("SELECT * FROM articles WHERE name = :name AND lang = :language;");
		$res->bindParam(':name',$name);
		$res->bindParam(':language',$this->language);
		$res->execute();
		return $res->fetchObject("Articles\Article",[$this]);
	}

	public function PDO() : \PDO {
		return $this->connection;
	}

}