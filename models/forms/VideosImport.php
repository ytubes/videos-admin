<?php

namespace backend\modules\videos\models\forms;

use Yii;
use SplFileObject;

use yii\base\InvalidParamException;

use yii\web\UploadedFile;
use yii\web\NotFoundHttpException;

use yii\helpers\FileHelper;
use yii\helpers\StringHelper;

use backend\modules\videos\models\Videos;
use backend\modules\videos\models\VideosCategories;
use backend\modules\videos\models\VideosImages;
use backend\modules\videos\models\VideosStats;

/**
 * Пометка: Сделать проверку на соответствие полей. Если не соответствует - писать в лог.
 */

/**
 * Модель для обработки формы импорта категорий через цсв файлы или просто текст.
 */
class VideosImport extends \yii\base\Model
{
	public $delimiter;
    public $enclosure;
    public $fields;

    public $csv_rows;
    public $csv_file;

    public $skip_duplicate_urls;
    public $skip_duplicate_embeds;

    public $skip_new_categories;

    protected $categories;

    protected $options = [
    	['value' => 'skip', 'text' => 'Пропустить'],
    	['value' => 'video_id', 'text'  => 'ID видео'],
    	['value' => 'title', 'text'  => 'Название'],
    	['value' => 'slug', 'text'  => 'Слаг'],
    	['value' => 'description', 'text'  => 'Описание'],
    	['value' => 'short_description', 'text'  => 'Короткое описание'],
    	['value' => 'duration', 'text'  => 'Длительность'],
    	['value' => 'video_url', 'text'  => 'URL видео'],
    	['value' => 'source_url', 'text'  => 'URL источника'],
    	['value' => 'embed', 'text'  => 'Embed код'],
    	['value' => 'likes', 'text'  => 'Лайки'],
    	['value' => 'dislikes', 'text'  => 'Дизлайки'],
    	['value' => 'views', 'text'  => 'Просмотры'],
    	['value' => 'published_at', 'text'  => 'Дата публикации'],
    	['value' => 'categories', 'text'  => 'Категории'],
    	['value' => 'images', 'text'  => 'Скриншоты'],
    ];


    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['delimiter', 'fields'], 'required'],
            ['fields', 'each', 'rule' => ['string'], 'skipOnEmpty' => false],
            [['delimiter', 'enclosure', 'csv_rows'], 'filter', 'filter' => 'trim'],
            [['delimiter', 'enclosure', 'csv_rows'], 'string'],
            [['skip_duplicate_urls', 'skip_duplicate_embeds', 'skip_new_categories'], 'boolean'],
            //['replace', 'default', 'value' => false],

            [['csv_file'], 'file', 'checkExtensionByMimeType' => false, 'skipOnEmpty' => true, 'extensions' => 'csv', 'maxFiles' => 1, 'mimeTypes' => 'text/plain'],
        ];
    }

    /**
     * Проверяет правильность данных в файле или текстовом поле. Затем сохраняет в базу.
     * @return boolean whether the model passes validation
     */
	public function save()
	{
		$this->csv_file = UploadedFile::getInstanceByName('csv_file');
		$this->categories = VideosCategories::find()
			->indexBy('title')
			->all();

		if ($this->validate()) {

				// Если загружен файл, читаем с него.
			if ($this->csv_file instanceof UploadedFile) {
				$filepath = Yii::getAlias('@runtime/tmp/' . $this->csv_file->baseName . '.' . $this->csv_file->extension);
				$this->csv_file->saveAs($filepath);

				$file = new SplFileObject($filepath);
				$file->setFlags(SplFileObject::READ_CSV|SplFileObject::READ_AHEAD|SplFileObject::SKIP_EMPTY|SplFileObject::DROP_NEW_LINE);
				$file->setCsvControl($this->delimiter, $this->enclosure);

				foreach ($file as $csvParsedString) {

					$newVideo = [];
					foreach ($this->fields as $key => $field) {
						if (isset($csvParsedString[$key]) && $field !== 'skip') {
							$newVideo[$field] = trim($csvParsedString[$key]);
						}
					}

					if (empty($newVideo)) {
						continue;
					}

					$this->insertVideo($newVideo);
				}

				@unlink($filepath);

				// Если файла нет, но загружено через текстовое поле, то будем читать с него.
			} elseif (!empty($this->csv_rows) || $this->csv_rows !== '') {

				$rows = explode("\n", trim($this->csv_rows, " \t\n\r\0\x0B"));

				foreach ($rows as $row) {
					$row = trim($row, " \t\n\r\0\x0B");

					$csvParsedString = str_getcsv($row, $this->delimiter, $this->enclosure);

					$newVideo = [];
					foreach ($this->fields as $key => $field) {
						if (isset($csvParsedString[$key]) && $field !== 'skip') {
							$newVideo[$field] = trim($csvParsedString[$key]);
						}
					}

					if (empty($newVideo)) {
						continue;
					}

					$this->insertVideo($newVideo);
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Осуществляет вставку видео. Если видео уже существут в базе (проверяется по source_url и embed). То вставка просто игнорируется.
	 * @return boolean была ли произведена вставка
	 */
	protected function insertVideo($newVideo)
	{
			// Ищем, существует ли видео по иду.
		if (isset($newVideo['video_id'])) {
			$video = Videos::find()
				->where(['video_id' => $newVideo['video_id']])
				->one();

			if ($video instanceof Videos) {
				return true;
			}
		}

			// Ищем, существует ли видео по урлу источника.
		if ($this->skip_duplicate_urls == 1 && isset($newVideo['source_url'])) {
			$video = Videos::find()
				->where(['source_url' => $newVideo['source_url']])
				->one();

			if ($video instanceof Videos) {
				return true;
			}
		}

			// Ищем, существует ли видео по embed коду.
		if ($this->skip_duplicate_embeds == 1 && isset($newVideo['embed'])) {
			$video = Videos::find()
				->where(['embed' => $newVideo['embed']])
				->one();

			if ($video instanceof Videos) {
				return true;
			}
		}

			// Если ничего не нашлось, будем вставлять новый.
		$video = new Videos();

			// Если у видео есть категории, вынесем их в отдельный массив.
		$videoCategories = [];
		if (isset($newVideo['categories'])) {
			$videoCategories = explode(',', $newVideo['categories']);
			unset($newVideo['categories']);
		}
			// Если у видео есть скриншоты, вынесем их в отдельный массив.
		$videoScreenshots = [];
		if (isset($newVideo['images'])) {
			$videoScreenshots = explode(',', $newVideo['images']);
			unset($newVideo['images']);
		}


		$video->attributes = $newVideo;

		if (!isset($newVideo['slug']) || empty($newVideo['slug'])) {
			//$video->slug = URLify::filter($newVideo['title']);
			$slug = \URLify::filter($newVideo['title']);
		} else {
			$slug = trim($newVideo['slug']);
		}

		$video->slug = $this->generateSlug($slug);

		$video->updated_at = gmdate('Y:m:d H:i:s');
		$video->created_at = gmdate('Y:m:d H:i:s');

		if ($video->save(true)) {

			$categories = [];
			if (!(empty($videoCategories))) {

				foreach ($videoCategories as $videoCategory) {
					$categoryTitle = trim(strip_tags($videoCategory));
						// Если категории не существует и флажок "не создавать новые" выключен, добавим категорию.
					if (!isset($this->categories[$categoryTitle]) && $this->skip_new_categories == false) {
						$category = new VideosCategories();

						$category->title = $categoryTitle;
						$category->slug = \URLify::filter($categoryTitle);
						$category->meta_title = $categoryTitle;
						$category->h1 = $categoryTitle;
						$category->updated_at = gmdate('Y:m:d H:i:s');
						$category->created_at = gmdate('Y:m:d H:i:s');
						$category->save();

						$this->categories[$categoryTitle] = $category;
					}

					if (isset($this->categories[$categoryTitle])) {
						$categories[] = $this->categories[$categoryTitle];
					}
				}
			}

			$screenshots = [];
			if (!(empty($videoScreenshots))) {

				foreach ($videoScreenshots as $key => $videoScreenshot) {
					$screenshot = new VideosImages();

					$screenshot->video_id = $video->video_id;
					$screenshot->position = $key;
					$screenshot->source_url = trim($videoScreenshot);
					$screenshot->status = 0;
					$screenshot->created_at = gmdate('Y:m:d H:i:s');

					if ($screenshot->save(true)) {
						$screenshots[] = $screenshot;
						if ($key === 0) {
							$video->link('image', $screenshot);
						}
					}
				}
			}

			if (!empty($categories) && !empty($screenshots)) {

				foreach ($categories as $category) {
					foreach ($screenshots as $sKey => $screenshot) {
						$videoStats = new VideosStats();

						$videoStats->video_id = $video->video_id;
						$videoStats->category_id = $category->category_id;
						$videoStats->image_id = $screenshot->image_id;
						$videoStats->published_at = $video->published_at;
						$videoStats->duration = (int) $video->duration;

						if ($sKey === 0) {
							$videoStats->best_image = 1;
						}

						$videoStats->save();
					}
				}
			}

		}

		unset($screenshots, $categories);
	}

	/**
	 * Осуществляет вставку категории. Если таковая уже существует (чек по тайтлу и иду) то проверяется флажок, перезаписывать или нет.
	 * В случае перезаписи назначает новые параметры исходя из данных файла.
	 * @return boolean было ли произведено обновление или вставка
	 */
	protected function insertCategory($newCategory)
	{
			// Ищем, существует ли категория.
		/*if (isset($newCategory['category_id'])) {
			$category = VideosCategories::find()
				->where(['category_id' => $newCategory['category_id']])
				->one();
		} elseif (isset($newCategory['title'])) {
			$category = VideosCategories::find()
				->where(['title' => $newCategory['title']])
				->one();
		} else {
			throw new InvalidParamException();
		}

			// Если ничего не нашлось, будем вставлять новый.
		if (!($category instanceof VideosCategories)) {
			$category = new VideosCategories();
		} else {
				// Если переписывать не нужно существующую категорию, то просто проигнорировать ее.
			if ($this->replace == false) {
				return true;
			}
		}

		$category->attributes = $newCategory;

		if (!isset($newCategory['slug']) || empty($newCategory['slug'])) {
			$category->slug = URLify::filter($newCategory['title']);
		}

		if ($category->isNewRecord) {
			$category->updated_at = gmdate('Y:m:d H:i:s');
			$category->created_at = gmdate('Y:m:d H:i:s');
		} else {
			$category->updated_at = gmdate('Y:m:d H:i:s');
		}

		return $category->save(true);*/
	}

	/**
	 *	Генерирует slug исходя из title. Также присоединяет численный суффикс, если слаг не уникален.
	 *
	 *	@param string $title
	 *	@return string
	 */
	private function generateSlug($title)
	{
		$slug = \URLify::filter($title);

		if (!$slug)
			$slug = 'default-video';

		if ($this->checkUniqueSlug($slug)) {
			return $slug;
		} else {
			for ($suffix = 1; !$this->checkUniqueSlug($new_slug = $slug . '-' . $suffix); $suffix++ ) {}
			return $new_slug;
		}
	}

	/**
	 *	Проверяет является ли slug уникальным. Верент true, если уникален.
	 *
	 *	@param string $slug
	 *	@return bool
	 */
	private function checkUniqueSlug($slug)
	{
		$sql = "SELECT `video_id` FROM `" . Videos::tableName() . "` WHERE `slug`='{$slug}'";

		$id = Yii::$app->db->createCommand($sql)
           ->queryOne();

		return false === $id;
	}

	/**
	 * @return array
	 */
	public function getOptions()
	{
		return $this->options;
	}
}