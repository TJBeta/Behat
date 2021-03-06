<?php

/*
 * This file is part of the Behat Testwork.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\Testwork\Tester\ServiceContainer;

use Behat\Testwork\Cli\ServiceContainer\CliExtension;
use Behat\Testwork\Environment\ServiceContainer\EnvironmentExtension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Behat\Testwork\ServiceContainer\ServiceProcessor;
use Behat\Testwork\Specification\ServiceContainer\SpecificationExtension;
use Behat\Testwork\Suite\ServiceContainer\SuiteExtension;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Provides tester services.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
abstract class TesterExtension implements Extension
{
    /*
     * Available services
     */
    const EXERCISE_ID = 'tester.exercise';
    const SUITE_TESTER_ID = 'tester.suite';
    const SPECIFICATION_TESTER_ID = 'tester.specification';
    const RESULT_INTERPRETER_ID = 'tester.result.interpreter';

    /**
     * Available extension points
     */
    const EXERCISE_WRAPPER_TAG = 'tester.exercise.wrapper';
    const SUITE_TESTER_WRAPPER_TAG = 'tester.suite.wrapper';
    const SPECIFICATION_TESTER_WRAPPER_TAG = 'tester.specification.wrapper';
    const RESULT_INTERPRETATION_TAG = 'test.result.interpretation';

    /**
     * @var ServiceProcessor
     */
    private $processor;

    /**
     * Initializes extension.
     *
     * @param null|ServiceProcessor $processor
     */
    public function __construct(ServiceProcessor $processor = null)
    {
        $this->processor = $processor ? : new ServiceProcessor();
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigKey()
    {
        return 'testers';
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(ExtensionManager $extensionManager)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function configure(ArrayNodeDefinition $builder)
    {
        $builder
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('strict')
            ->defaultFalse()
            ->end()
            ->booleanNode('skip')
            ->defaultFalse()
            ->end()
            ->end();
    }

    /**
     * {@inheritdoc}
     */
    public function load(ContainerBuilder $container, array $config)
    {
        $this->loadExerciseController($container, $config['skip']);
        $this->loadStrictController($container, $config['strict']);
        $this->loadResultInterpreter($container);
        $this->loadExercise($container);
        $this->loadSuiteTester($container);
        $this->loadSpecificationTester($container);
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $this->processExerciseWrappers($container);
        $this->processSuiteTesterWrappers($container);
        $this->processSpecificationTesterWrappers($container);
        $this->processResultInterpretations($container);
    }

    /**
     * Loads exercise cli controllers.
     *
     * @param ContainerBuilder $container
     * @param Boolean          $skip
     */
    protected function loadExerciseController(ContainerBuilder $container, $skip = false)
    {
        $definition = new Definition('Behat\Testwork\Tester\Cli\ExerciseController', array(
            new Reference(SuiteExtension::REGISTRY_ID),
            new Reference(SpecificationExtension::FINDER_ID),
            new Reference(self::EXERCISE_ID),
            new Reference(self::RESULT_INTERPRETER_ID),
            $skip
        ));
        $definition->addTag(CliExtension::CONTROLLER_TAG, array('priority' => 0));
        $container->setDefinition(CliExtension::CONTROLLER_TAG . '.exercise', $definition);
    }

    /**
     * Loads exercise cli controllers.
     *
     * @param ContainerBuilder $container
     * @param Boolean          $strict
     */
    protected function loadStrictController(ContainerBuilder $container, $strict = false)
    {
        $definition = new Definition('Behat\Testwork\Tester\Cli\StrictController', array(
            new Reference(self::RESULT_INTERPRETER_ID),
            $strict
        ));
        $definition->addTag(CliExtension::CONTROLLER_TAG, array('priority' => 10));
        $container->setDefinition(CliExtension::CONTROLLER_TAG . '.strict', $definition);
    }

    /**
     * Loads result interpreter controller
     *
     * @param ContainerBuilder $container
     */
    protected function loadResultInterpreter(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Testwork\Tester\Result\ResultInterpreter');
        $container->setDefinition(self::RESULT_INTERPRETER_ID, $definition);

        $definition = new Definition('Behat\Testwork\Tester\Result\Interpretation\SoftInterpretation');
        $definition->addTag(self::RESULT_INTERPRETATION_TAG);
        $container->setDefinition(self::RESULT_INTERPRETATION_TAG . '.soft', $definition);
    }

    /**
     * Loads exercise tester.
     *
     * @param ContainerBuilder $container
     */
    protected function loadExercise(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Testwork\Tester\Runtime\RuntimeExercise', array(
            new Reference(EnvironmentExtension::MANAGER_ID),
            new Reference(self::SUITE_TESTER_ID)
        ));
        $container->setDefinition(self::EXERCISE_ID, $definition);
    }

    /**
     * Loads suite tester.
     *
     * @param ContainerBuilder $container
     */
    protected function loadSuiteTester(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Testwork\Tester\Runtime\RuntimeSuiteTester', array(
            new Reference(self::SPECIFICATION_TESTER_ID)
        ));
        $container->setDefinition(self::SUITE_TESTER_ID, $definition);
    }

    /**
     * Loads specification tester.
     *
     * @param ContainerBuilder $container
     */
    abstract protected function loadSpecificationTester(ContainerBuilder $container);

    /**
     * Processes all registered exercise wrappers.
     *
     * @param ContainerBuilder $container
     */
    protected function processExerciseWrappers(ContainerBuilder $container)
    {
        $references = $this->processor->findAndSortTaggedServices($container, self::EXERCISE_WRAPPER_TAG);

        foreach ($references as $reference) {
            $wrappedTester = $container->getDefinition(self::EXERCISE_ID);
            $wrappingTester = $container->getDefinition((string)$reference);
            $wrappingTester->replaceArgument(0, $wrappedTester);

            $container->setDefinition(self::EXERCISE_ID, $wrappingTester);
        }
    }

    /**
     * Processes all registered suite tester wrappers.
     *
     * @param ContainerBuilder $container
     */
    protected function processSuiteTesterWrappers(ContainerBuilder $container)
    {
        $references = $this->processor->findAndSortTaggedServices($container, self::SUITE_TESTER_WRAPPER_TAG);

        foreach ($references as $reference) {
            $wrappedTester = $container->getDefinition(self::SUITE_TESTER_ID);
            $wrappingTester = $container->getDefinition((string)$reference);
            $wrappingTester->replaceArgument(0, $wrappedTester);

            $container->setDefinition(self::SUITE_TESTER_ID, $wrappingTester);
        }
    }

    /**
     * Processes all registered specification tester wrappers.
     *
     * @param ContainerBuilder $container
     */
    protected function processSpecificationTesterWrappers(ContainerBuilder $container)
    {
        $references = $this->processor->findAndSortTaggedServices($container, self::SPECIFICATION_TESTER_WRAPPER_TAG);

        foreach ($references as $reference) {
            $wrappedTester = $container->getDefinition(self::SPECIFICATION_TESTER_ID);
            $wrappingTester = $container->getDefinition((string)$reference);
            $wrappingTester->replaceArgument(0, $wrappedTester);

            $container->setDefinition(self::SPECIFICATION_TESTER_ID, $wrappingTester);
        }
    }

    /**
     * Processes all registered result interpretations.
     *
     * @param ContainerBuilder $container
     */
    protected function processResultInterpretations(ContainerBuilder $container)
    {
        $references = $this->processor->findAndSortTaggedServices($container, self::RESULT_INTERPRETATION_TAG);
        $definition = $container->getDefinition(self::RESULT_INTERPRETER_ID);

        foreach ($references as $reference) {
            $definition->addMethodCall('registerResultInterpretation', array($reference));
        }
    }
}
