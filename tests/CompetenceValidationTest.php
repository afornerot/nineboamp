<?php

namespace App\Tests;

use App\Entity\Competence;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Le formulaire convertit un champ vide en null : l'entité doit l'accepter et
 * la validation le refuser, au lieu d'une TypeError (erreur 500).
 */
class CompetenceValidationTest extends TestCase
{
    public function testBlankLabelIsAValidationErrorNotATypeError(): void
    {
        $competence = (new Competence())->setLabel(null);
        $this->assertSame(['label'], $this->invalidProperties($competence));

        $competence->setLabel("  \n ");
        $this->assertSame(['label'], $this->invalidProperties($competence));
    }

    public function testEmptyPositionIsAValidationErrorNotATypeError(): void
    {
        $competence = (new Competence())->setLabel('Formation')->setPosition(null);

        $this->assertSame(['position'], $this->invalidProperties($competence));
        $this->assertSame(0, $competence->getPosition());
    }

    public function testTooLongLabelAndUnknownCategoryAreRefused(): void
    {
        $competence = (new Competence())->setLabel(str_repeat('a', Competence::LABEL_MAX_LENGTH + 1))->setCategory('inconnue');

        $this->assertSame(['category', 'label'], $this->invalidProperties($competence));
    }

    public function testAFilledCompetenceIsValid(): void
    {
        $this->assertSame([], $this->invalidProperties((new Competence())->setLabel('Formation')->setPosition(10)));
    }

    /**
     * @return string[]
     */
    private function invalidProperties(Competence $competence): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $properties = [];
        foreach ($validator->validate($competence) as $violation) {
            $properties[] = $violation->getPropertyPath();
        }
        sort($properties);

        return array_values(array_unique($properties));
    }
}
