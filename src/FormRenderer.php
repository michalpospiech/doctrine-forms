<?php
/**
 * FormRenderer.php
 *
 * @author Michal Pospiech <michal@pospiech.cz>
 */

namespace mpospiech\Doctrine\Forms;


use Nette\Application\UI\ITemplateFactory;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\CheckboxList;
use Nette\Forms\Controls\MultiSelectBox;
use Nette\Forms\Controls\RadioList;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\SubmitButton;
use Nette\Forms\Controls\TextBase;
use Nette\Forms\IControl;
use Nette\Forms\Rendering\DefaultFormRenderer;
use Nette\Utils\Html;

class FormRenderer extends DefaultFormRenderer
{

	/**
	 * @var Button
	 */
	public $primaryButton = null;

	/**
	 * @var bool
	 */
	private $controlsInit = false;

	/**
	 * @var int
	 */
	private $labelCols;

	/**
	 * @var int
	 */
	private $controlCols;

	/**
	 * @var bool
	 */
	private $ajax;

	/**
	 * @var ITemplateFactory
	 */
	private $templateFactory;

	/**
	 * @var string
	 */
	private $templateFile;

	public function __construct($labelCols = 3, $controlCols = 9, $ajax = false)
	{
		$this->labelCols = $labelCols;
		$this->controlCols = $controlCols;
		$this->setAjax($ajax);

		$this->wrappers['controls']['container'] = null;
		$this->wrappers['pair']['container'] = 'div class=form-group';
		$this->wrappers['pair']['.error'] = 'has-error';
		$this->wrappers['control']['container'] = 'div class=col-sm-' . $this->controlCols;
		$this->wrappers['label']['container'] = 'div class="control-label col-sm-' . $this->labelCols . '"';
		$this->wrappers['control']['description'] = 'span class=help-block';
		$this->wrappers['control']['errorcontainer'] = 'span class=help-block';
	}

	public function setTemplate($templateFile, ITemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
		if (is_file($templateFile)) {
			$this->templateFile = $templateFile;
		}
	}

	public function setAjax($ajax = true)
	{
		$this->ajax = $ajax;
	}

	public function render(\Nette\Forms\Form $form, $mode = NULL)
	{
		if ($this->templateFile) {
			if ($this->form !== $form) {
				$this->form = $form;
			}

			$this->controlsInit();

			$template = $this->templateFactory->createTemplate();
			$template->name = 'template_' . $this->form->getName();
			$template->setFile($this->templateFile);
			$template->form = $this->form;
			$template->mode = $mode;

			return $template;
		} else {
			return parent::render($form, $mode);
		}
	}

	public function renderBegin()
	{
		$this->controlsInit();
		return parent::renderBegin();
	}

	public function renderEnd()
	{
		$this->controlsInit();
		return parent::renderEnd();
	}

	public function renderBody()
	{
		$this->controlsInit();
		return parent::renderBody();
	}

	public function renderControls($parent)
	{
		$this->controlsInit();
		return parent::renderControls($parent);
	}

	public function renderPair(IControl $control)
	{
		$this->controlsInit();
		return parent::renderPair($control);
	}

	public function renderPairMulti(array $controls)
	{
		$this->controlsInit();
		return parent::renderPairMulti($controls);
	}

	public function renderLabel(IControl $control)
	{
		$this->controlsInit();
		return parent::renderLabel($control);
	}

	public function renderControl(IControl $control)
	{
		$this->controlsInit();

		$body = $this->getWrapper('control container');
		if ($this->counter % 2) {
			$body->class($this->getValue('control .odd'), TRUE);
		}

		$description = $control->getOption('description');
		if ($description instanceof Html) {
			$description = ' ' . $description;

		} elseif (is_string($description)) {
			$description = ' ' . $this->getWrapper('control description')->setText($control->translate($description));

		} else {
			$description = '';
		}

		if ($control->isRequired()) {
			$description = $this->getValue('control requiredsuffix') . $description;
		}

		$control->setOption('rendered', TRUE);
		$el = $control->getControl();
		if ($el instanceof Html && $el->getName() === 'input') {
			$el->class($this->getValue("control .$el->type"), TRUE);
		}

		if ($control->getOption('help')) {
			$el->addAttributes(['data-toggle' => 'tooltip', 'data-placement' => 'bottom', 'title' => $control->getOption('help')]);
		}

		$appendText = $control->getOption('append');
		if ($appendText instanceof Html || is_string($appendText)) {
			$el = Html::el('div', ['class' => 'input-group'])->addHtml($el);

			if (is_string($appendText)) {
				$appendText = Html::el('div', ['class' => 'input-group-addon'])->setText($appendText);
			}

			$el->addHtml($appendText);
		}

		return $body->setHtml($el . $description . $this->renderErrors($control));
	}

	private function controlsInit()
	{
		if ($this->controlsInit) {
			return;
		}

		$this->controlsInit = true;
		$this->form->getElementPrototype()->addClass('form-horizontal')->role('form');

		if ($this->ajax) {
			$this->form->getElementPrototype()->addClass('ajax');
		}

		foreach ($this->form->getControls() as $control) {
			if ($control instanceof SubmitButton) {
				$markAsPrimary = $control === $this->primaryButton || (!isset($this->primaryButton) && empty($usedPrimary) && $control->parent instanceof Form);
				if ($markAsPrimary) {
					$class = 'btn btn-primary';
					$usedPrimary = true;
				} else {
					$class = 'btn btn-default';
				}
				$control->getControlPrototype()->addClass($class);
			} else if ($control instanceof Button) {
				$control->getControlPrototype()->addClass('btn btn-default');
			} else if ($control instanceof TextBase || $control instanceof SelectBox || $control instanceof MultiSelectBox) {
				$control->getControlPrototype()->addClass('form-control');
			} else if ($control instanceof Checkbox || $control instanceof CheckboxList || $control instanceof RadioList) {
				if ($control->getSeparatorPrototype()->getName() !== null) {
					$control->getSeparatorPrototype()->setName('div')->addClass($control->getControlPrototype()->type);
				} else {
					$control->getItemLabelPrototype()->addClass($control->getControlPrototype()->type . '-inline');
				}
			}
		}
	}

}