<?php

namespace App\Security;

use App\Entity\Project;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ProjectVoter extends Voter
{
    const VIEW = 'view';
    const EDIT = 'edit';
    const DELETE = 'delete';
    const ARCHIVE = 'archive';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::ARCHIVE])
            && $subject instanceof Project;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Project $project */
        $project = $subject;

        return match($attribute) {
            self::VIEW => $this->canView($project, $user),
            self::EDIT => $this->canEdit($project, $user),
            self::DELETE => $this->canDelete($project, $user),
            self::ARCHIVE => $this->canArchive($project, $user),
            default => false
        };
    }

    private function canView(Project $project, User $user): bool
    {
        return $project->getUser() === $user;
    }

    private function canEdit(Project $project, User $user): bool
    {
        return $project->getUser() === $user;
    }

    private function canDelete(Project $project, User $user): bool
    {
        return $project->getUser() === $user;
    }

    private function canArchive(Project $project, User $user): bool
    {
        return $project->getUser() === $user;
    }
}
