<?php
/**
 * FormFactory.php
 *
 * @author Michal Pospiech <michal@pospiech.cz>
 */

namespace mpospiech\Doctrine\Forms;


use Doctrine\ORM\Mapping\MappingException;
use Kdyby\Doctrine;
use Kdyby\Doctrine\Collections\ReadOnlyCollectionWrapper;
use Nette\Application\UI;
use Nette\Forms\Container;
use Nette\Forms\Controls\MultiSelectBox;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\TextBase;
use Nette\Forms\Form;
use Nette\Forms\Rule;
use Nette\InvalidArgumentException;
use Nette\Object;
use Nette\Utils\Validators;
use Tracy\Debugger;
use Tracy\ILogger;

abstract class FormFactory extends Object implements IFormFactory
{

	/** @var Doctrine\EntityManager */
	private $entityManager;

	/** @var UI\ITemplateFactory */
	private $templateFactory;

	/** @var Doctrine\QueryBuilder */
	protected $queryBuilder;

	/** @var string */
	protected $entityName;

	/** @var object */
	protected $entity;

	/** @var Doctrine\EntityRepository */
	protected $repository;

	/** @var array */
	protected $mapping;

	/** @var bool */
	protected $isAjax = false;

	/** @var bool */
	protected $setSubmitButton = false;

	/** @var bool */
	protected $setIdElement = false;

	/** @var int */
	protected $labelCols = 3;

	/** @var int */
	protected $controlCols = 9;

	/** @var Form */
	protected $form;

	/** @var string */
	protected $templateFile;

	/** @var callable */
	public $onBeforeSuccess = [];

	/** @var callable */
	public $onAfterSuccess = [];

	/** @var callable */
	public $onAfterError = [];

	const DATABASE_METHOD_NONE = 'none';
	const DATABASE_METHOD_INSERT = 'insert';
	const DATABASE_METHOD_UPDATE = 'update';

	/**
	 * FormFactory constructor
	 *
	 * @param Doctrine\EntityManager $entityManager
	 * @param UI\ITemplateFactory $templateFactory
	 */
	public function __construct(Doctrine\EntityManager $entityManager, UI\ITemplateFactory $templateFactory)
	{
		$this->entityManager = $entityManager;
		$this->templateFactory = $templateFactory;
	}

	/**
	 * K formulari se ma automaticky pridat submit button
	 *
	 * @param bool $set
	 * @return self
	 */
	public function setSubmitButton($set = true)
	{
		$this->setSubmitButton = $set;

		return $this;
	}

	/**
	 * Nastavi individualni sablonu formulare
	 *
	 * @param string $file
	 */
	public function setTemplate($file)
	{
		if (!is_file($file)) {
			throw new InvalidArgumentException('Missing template file ' . $file);
		}

		$this->templateFile = $file;
	}

	public function getForm()
	{
		// sestaveni formulare
		$this->setupForm($this->form);

		// donastaveni hodnot formulari
		$this->afterSetupForm();

		return $this->form;
	}

	/**
	 * Vrati typ zapisu do databaze(none, insert, update)
	 *
	 * @return string
	 */
	public final function getDatabaseMethod()
	{
		if (!$this->entity) {
			return self::DATABASE_METHOD_NONE;
		}

		if ($this->entity && property_exists($this->entity, 'id') && $this->entity->getId()) {
			return self::DATABASE_METHOD_UPDATE;
		} else {
			return self::DATABASE_METHOD_INSERT;
		}
	}

	/**
	 * Vytvoreni komponenty formulare
	 *
	 * @param string|null $entityName
	 * @param int $uniqueId
	 * @param bool $ajax
	 * @param bool $setSubmitButton
	 * @return self
	 */
	public function create($entityName = null, $uniqueId = 0, $ajax = false, $setSubmitButton = true)
	{
		$this->isAjax = $ajax;
		$this->setSubmitButton($setSubmitButton);

		$this->form = new UI\Form();

		// pripravime entitu
		$this->prepareEntity($entityName, $uniqueId);

		return $this;
	}

	abstract protected function setupForm(Form $form);

	/**
	 * Zpracovani formulare
	 *
	 * @param Form $form
	 * @param $values
	 */
	protected final function success(Form $form, $values)
	{
		$this->onBeforeSuccess($values, $this->entity, $form);

		$error = false;
		try {
			$this->successProcess($form, $values);
		} catch (\Exception $e) {
			$error = true;
			Debugger::log($e, ILogger::EXCEPTION);
		}

		if (!$error) {
			$this->onAfterSuccess($values, $this->entity, $form);
		} else {
			$this->onAfterError($values, $form);
		}
	}

	/**
	 * Prida callback, ktery se zavola po uspesnem zpracovani formulare
	 *
	 * @param callable $callback
	 * @return $this
	 */
	public final function addAfterSuccess(callable $callback)
	{
		$this->onAfterSuccess[] = $callback;

		return $this;
	}

	/**
	 * Prida callback, ktery se zavola po chybnem zpracovani formulare
	 *
	 * @param callable $callback
	 * @return $this
	 */
	public final function addAfterError(callable $callback)
	{
		$this->onAfterError[] = $callback;

		return $this;
	}

	/**
	 * Zpracovani dat z formulare
	 *
	 * @param Form $form
	 * @param $values
	 */
	protected function successProcess(Form $form, $values)
	{
		// pokud zname entitu, zapiseme/aktualizujeme data
		if ($this->entity) {
			if (property_exists($values, 'id')) {
				$valueId = $values->id;
				unset($values->id);
			} else {
				$valueId = null;
			}

			// v pripade, ze zname ID z formulare, ale entita je prazdna, tak nastavime
			if (!$this->entity->getId() && $valueId) {
				$this->prepareEntity($this->entityName, $valueId);
			}

			$entityMapping = $this->repository->getClassMetadata()->getAssociationMappings();
			foreach ($values as $key => $val) {
				// aktualizace referencniho klice
				if (!Validators::isNone($val) && array_key_exists($key, $entityMapping)) {
					$subEntity = $this->entityManager->getRepository($entityMapping[$key]['targetEntity']);

					// pokud existuje metoda add... tak vyuzijeme - v opacnem pripade pouze $val priradime danou entitu a nize ulozime beznym zpusobem
					if (method_exists($this->entity, 'add' . $key) && !is_array($val)) { // referencni klic
						$this->entity->{'add' . $key}($subEntity->find($val));
						continue;
					} else if (method_exists($this->entity, 'add' . $key)) { // multiselect
						$entities = $subEntity->findBy(['id' => array_values($val)]);
						$this->entity->{'add' . $key}($entities);
						continue;
					}

					$val = $subEntity->find($val);
				}

				if (method_exists($this->entity, 'set' . $key)) {
					$this->entity->{'set' . $key}($val);
				} else if (property_exists($this->entity, $key)) {
					$this->entity->$key = $val;
				}
			}

			$this->entityManager->persist($this->entity);
			$this->entityManager->flush();
		}
	}

	/**
	 * Pripravi entitu
	 *
	 * @param string|null $entityName
	 * @param int $uniqueId
	 */
	private function prepareEntity($entityName = null, $uniqueId = 0)
	{
		$this->entityName = $entityName;

		// pokud je definovana entita a primarni klic, vezmeme data z databaze
		if (!Validators::isNone($this->entityName) && !Validators::isNone($uniqueId)) {
			try {
				$this->repository = $this->entityManager->getRepository($this->entityName);

				$query = $this->repository->createQueryBuilder('t')
					->select('t')
					->where('t.id = :id')->setParameter('id', $uniqueId);

				$i = 1;
				foreach ($this->repository->getClassMetadata()->getAssociationNames() as $associationName) {
					if (!$this->repository->getClassMetadata()->isAssociationWithSingleJoinColumn($associationName)) {
						continue;
					}

					$query->leftJoin(sprintf('t.%s', $associationName), sprintf('t.%d', $i));
					$query->addSelect(sprintf('t%d', $i));

					$this->mapping[$associationName] = $this->repository->getClassMetadata()->getSingleAssociationJoinColumnName($associationName);
					$i++;
				}

				$this->queryBuilder = $query;
				$this->entity = $query->getQuery()->getOneOrNullResult();
			} catch (Doctrine\MissingClassException $exception) {
				$this->queryBuilder = null;
				$this->entity = null;
			}
		}
		// pokud je definovana pouze entita, tak pripravime po zapis
		else if (!Validators::isNone($this->entityName)) {
			try {
				$this->repository = $this->entityManager->getRepository($this->entityName);
				$this->entity = new $entityName();
			} catch (Doctrine\MissingClassException $exception) {
				$this->entity = null;
			}
		}
	}

	/**
	 * Donastaveni formulare
	 */
	private function afterSetupForm()
	{
		// pokud neexistuje prvek se jmenem ID, tak vytvorime
		$isId = false;
		foreach ($this->form->getComponents() as $component) {
			if ($component->getName() === 'id') {
				$isId = true;
				break;
			}
		}
		if (!$isId && $this->setIdElement && $this->entity) {
			$this->form->addHidden('id', $this->entity->getId());
		}

		// pokud je entita, doplnime hodnoty
		if ($this->entity) {
			foreach ($this->form->getComponents(true) as $key => $component) {
				if (!property_exists($this->entity, $key) || $component instanceof Container) {
					continue;
				}

				// pokud jiz ma hodnotu, jdeme dal
				if ($component->getValue()) {
					continue;
				}

				// definujeme vychozi hodnoty
				$value = method_exists($this->entity, 'get' . $key) ? $this->entity->{'get' . $key}() : $this->entity->$key;
				if (Validators::is($value, 'bool')) {
					$component->setDefaultValue((int)$value);
				} else if (Validators::is($value, 'scalar')) {
					$component->setDefaultValue($value);
				} else if (Validators::is($value, 'array') && array_key_exists($key, $this->mapping)) {
					$component->setDefaultValue($value['id']);
				} else if ($value instanceof ReadOnlyCollectionWrapper && $component instanceof MultiSelectBox) {
					$defaultValue = [];
					foreach ($value->toArray() as $subEntity) {
						if (!array_key_exists($subEntity->getId(), $component->getItems())) {
							continue;
						}

						$defaultValue[] = $subEntity->getId();
					}
					$component->setDefaultValue($defaultValue);
				} else if (is_object($value) && array_key_exists($key, $this->repository->getClassMetadata()->getAssociationMappings()) && (method_exists($value, 'getId') || property_exists($value, 'id'))) {
					$id = method_exists($value, 'getid') ? $value->getid() : $value->id;

					// pokud se jedna o selectbox nebo multiselectbox a pozadovane ID neni v seznamu, tak preskocime
					if (($component instanceof SelectBox || $component instanceof MultiSelectBox) && !array_key_exists($id, $component->getItems())) {
						continue;
					}

					$component->setDefaultValue($id);
				}
			}
		}

		// pokud zname entitu a repository, tak donastavime formular
		if ($this->repository && $this->entity) {
			foreach ((array)$this->form->getComponents() as $key => &$component) {
				// nastaveni pravidel - pouze, pokud se podarilo ziskat nastaveni sloupce v DB
				try {
					$fieldMapping = $this->repository->getClassMetadata()->getFieldMapping($key);

					// MAX_LENGTH
					if ($component instanceof TextBase && in_array($fieldMapping['type'], ['string', 'text']) && $fieldMapping['length']) {
						$isMaxLengthRule = array_filter((array)$component->getRules()->getIterator(), function ($rule) {
							if (!($rule instanceof Rule)) {
								return false;
							}

							return $rule->validator === Form::MAX_LENGTH;
						});
						if (!$isMaxLengthRule) {
							$component->addRule(Form::MAX_LENGTH, null, $fieldMapping['length']);
						}
					}

					// INTEGER
					if ($component instanceof TextBase && $fieldMapping['type'] === 'integer') {
						$isIntegerRule = array_filter((array)$component->getRules()->getIterator(), function ($rule) {
							if (!$rule instanceof Rule) {
								return false;
							}

							return $rule->validator === Form::INTEGER;
						});
						if (!$isIntegerRule) {
							$component->addRule(Form::INTEGER);
						}
					}

					// REQUIRED
					if (
						!$fieldMapping['nullable'] &&
						($component instanceof TextBase || $component instanceof SelectBox || $component instanceof MultiSelectBox) &&
						!$component->getRules()->isRequired() &&
						!$component->getOption('noRequired')
					) {
						$component->setRequired();
					}
				} catch (MappingException $me) {
					$fieldMapping = null;
				}
			}
		}

		// pokud se ma vytvorit submit button, vytvorime
		$isButton = false;
		foreach ($this->form->getComponents(false, 'Nette\\Forms\\Controls\\SubmitButton') as $sb) {
			$isButton = true;
			break;
		}
		if ($this->setSubmitButton && !$isButton) {
			$this->form->addSubmit('save', ($this->getDatabaseMethod() === self::DATABASE_METHOD_INSERT ? 'Vytvořit' : 'Uložit'));
		}

		$this->form->onSuccess[] = [$this, 'success'];

		// nastavime renderer
		$formRenderer = new FormRenderer($this->labelCols, $this->controlCols);
		$formRenderer->setAjax($this->isAjax);
		if ($this->templateFile) {
			$formRenderer->setTemplate($this->templateFile, $this->templateFactory);
		}
		$this->form->setRenderer($formRenderer);
	}

}