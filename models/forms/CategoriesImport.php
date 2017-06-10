<?php

namespace backend\modules\videos\models\forms;

use Yii;
use SplFileObject;

use yii\base\InvalidParamException;

use yii\web\UploadedFile;
use yii\web\NotFoundHttpException;

use yii\helpers\FileHelper;
use yii\helpers\StringHelper;

use common\helpers\URLify;

use backend\modules\videos\models\VideosCategories;

/**
 * Модель для обработки формы импорта категорий через цсв файлы или просто текст.
 */
class CategoriesImport extends \yii\base\Model
{
	public $delimiter;
    public $enclosure;
    public $fields;

    public $csv_rows;
    public $csv_file;

    public $replace;

    protected $model;

    protected $options = [
    	['value' => 'skip', 'text' => 'Пропустить'],
    	['value' => 'category_id', 'text'  => 'id'],
    	['value' => 'title', 'text'  => 'Название'],
    	['value' => 'slug', 'text'  => 'Слаг'],
    	['value' => 'meta_title', 'text'  => 'Мета заголовок'],
    	['value' => 'meta_description', 'text'  => 'Мета описание'],
    	['value' => 'h1', 'text'  => 'Заголовок H1'],
    	['value' => 'description', 'text'  => 'Описание'],
    	['value' => 'seotext', 'text'  => 'СЕО текст'],
    	['value' => 'param1', 'text'  => 'Доп. поле 1'],
    	['value' => 'param2', 'text'  => 'Доп. поле 2'],
    	['value' => 'param3', 'text'  => 'Доп. поле 3'],
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
            [['replace'], 'boolean'],
            ['replace', 'default', 'value' => false],

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

		if ($this->validate()) {

				// Если загружен файл, читаем с него.
			if ($this->csv_file instanceof UploadedFile) {
				$filepath = Yii::getAlias('@runtime/tmp/' . $this->csv_file->baseName . '.' . $this->csv_file->extension);
				$this->csv_file->saveAs($filepath);

				$file = new SplFileObject($filepath);
				$file->setFlags(SplFileObject::READ_CSV|SplFileObject::READ_AHEAD|SplFileObject::SKIP_EMPTY|SplFileObject::DROP_NEW_LINE);
				$file->setCsvControl($this->delimiter, $this->enclosure);

				foreach ($file as $csvParsedString) {

					$newCategory = [];
					foreach ($this->fields as $key => $field) {
						if (isset($csvParsedString[$key]) && $field !== 'skip') {
							$newCategory[$field] = trim($csvParsedString[$key]);
						}
					}

					if (empty($newCategory)) {
						continue;
					}

					$this->insertCategory($newCategory);
				}

				@unlink($filepath);

				// Если файла нет, но загружено через текстовое поле, то будем читать с него.
			} elseif (!empty($this->csv_rows) || $this->csv_rows !== '') {

				$rows = explode("\n", trim($this->csv_rows, " \t\n\r\0\x0B"));

				foreach ($rows as $row) {
					$row = trim($row, " \t\n\r\0\x0B");

					$csvParsedString = str_getcsv($row, $this->delimiter, $this->enclosure);

					$newCategory = [];
					foreach ($this->fields as $key => $field) {
						if (isset($csvParsedString[$key]) && $field !== 'skip') {
							$newCategory[$field] = trim($csvParsedString[$key]);
						}
					}

					if (empty($newCategory)) {
						continue;
					}

					$this->insertCategory($newCategory);
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Осуществляет вставку категории. Если таковая уже существует (чек по тайтлу и иду) то проверяется флажок, перезаписывать или нет.
	 * В случае перезаписи назначает новые параметры исходя из данных файла.
	 * @return boolean было ли произведено обновление или вставка
	 */
	protected function insertCategory($newCategory)
	{
			// Ищем, существует ли категория.
		if (isset($newCategory['category_id'])) {
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

		return $category->save(true);
	}

	/**
	 * @return array
	 */
	public function getOptions()
	{
		return $this->options;
	}
}