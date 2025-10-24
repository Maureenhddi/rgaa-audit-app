<?php

namespace App\Security;

use App\Entity\Audit;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AuditVoter extends Voter
{
    const VIEW = 'view';
    const EDIT = 'edit';
    const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Audit;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Audit $audit */
        $audit = $subject;

        return match($attribute) {
            self::VIEW => $this->canView($audit, $user),
            self::EDIT => $this->canEdit($audit, $user),
            self::DELETE => $this->canDelete($audit, $user),
            default => false
        };
    }

    private function canView(Audit $audit, User $user): bool
    {
        return $audit->getUser() === $user;
    }

    private function canEdit(Audit $audit, User $user): bool
    {
        return $audit->getUser() === $user;
    }

    private function canDelete(Audit $audit, User $user): bool
    {
        return $audit->getUser() === $user;
    }
}
