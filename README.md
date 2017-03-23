# Doctrine Forms

## Instalace

```sh
$ composer require mpospiech/doctrine-forms
```

## Použití

Každý formulář musí být zaregistrován jako služba.
```yml
services:
	- App\Forms\BaseFormFactory
	- App\Forms\BaseFormChangeValuesFactory
	- App\Forms\BaseFormWithTemplateFactory
```

### Vytvoření komponenty formuláře v Presenteru
```php
class FormPresenter extends \Nette\Application\UI\Presenter {
	/** @var BaseFormFactory @inject */
    public $baseFormFactory;
    
    /** @var DoctrineEntity */
    private $currentEntity; // pokud se ma jednat o update
    
    public function createComponentBaseForm() {
    	$component = $this->baseFormFactory->create(DoctrineEntity::class, ($this->currentEntity ? $this->currentEntity->id : 0));
        
        $self = $this;
        $component->addAfterSuccess(function() use ($self) {
        	$self->redirect('this');
        });
        
        $form = $component->getForm();
        
        $form->onSuccess[] = function(\Nette\Forms\Form $form, \Nette\Utils\ArrayHash $values) use ($self) {
        	if ($values->id) {
            	$self->flashMessage('Hodnoty formuláře byly úspěšně upraveny.', 'success');
            } else {
            	$self->flashMessage('Hodnoty z formuláře byly úspěšně uloženy.', 'success');
            }
        };
        
        return $form;
    }
}
```

### Základní formulář

```php
class BaseFormFactory extends \mpospiech\Doctrine\Forms\FormFactory {
	public function setupForm(\Nette\Forms\Form $form) {
    	$form->addText('name', 'Název'); // pokud neni null v databazi, tak bude nastaven jako required
        
        $form->addTextArea('description', 'Popis');
        
        $form->addText('date', 'Datum')
        	->setAttribute('placeholder', 'dd.mm.YYYY');
    }
}
```

### Formulář se změnou hodnot před uložením

```php
class BaseFormChangeValuesFactory extends \mpospiech\Doctrine\Forms\FormFactory {
	public function setupForm(\Nette\Forms\Form $form) {
    	$form->addText('name', 'Název'); // pokud neni null v databazi, tak bude nastaven jako required
        
        $form->addTextArea('description', 'Popis');
        
        $form->addText('date', 'Datum');
        
        $this->onBeforeSuccess[] = [$this, 'change'];
    }
    
    public function change(\Nette\Utils\ArrayHash $values) {
    	$values->date = \Nette\Utils\DateTime::createFromFormat('d.m.Y', $values->date); // do entity pro hodnotu date bude nyni odeslana instance DateTime
    }
}
```

### Formulář s individuální šablonou

```php
class BaseFormWithTemplateFactory extends \mpospiech\Doctrine\Forms\FormFactory {
	public function __construct(\Kdyby\Doctrine\EntityManager $entityManager, \Nette\Application\UI\ITemplateFactory $templateFactory)
	{
		parent::__construct($entityManager, $templateFactory);

		$this->setTemplate(__DIR__ . '/baseForm.latte');
	}

	public function setupForm(\Nette\Forms\Form $form) {
    	$form->addText('name', 'Název'); // pokud neni null v databazi, tak bude nastaven jako required
        
        $form->addTextArea('description', 'Popis');
        
        $form->addText('date', 'Datum')
        	->setAttribute('placeholder', 'dd.mm.YYYY');
    }
}
```

#### Šablona
```html
{form $form}
	<div class="row">
		{label name /}
    	<input n:name="name">
    </div>
    <div class="row">
		{label description /}
    	<input n:name="description">
    </div>
    <div class="row">
		{label date /}
    	<input n:name="date">
    </div>
    
    <div class="row">
    	<input n:name="save">
    </div>
{/form}
```

#### Nastavování jednotlivých formulářových komponent
```php
// vypnute uložení hodnoty do databáze
$form->addText('name', 'nameValue')
    ->setOption('autoSet', false);

// vypnuté nastavení výchozí hodnoty
$form->addText('name', 'nameValue')
    ->setOption('setDefaultValue', false);
```