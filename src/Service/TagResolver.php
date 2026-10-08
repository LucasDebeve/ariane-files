<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

final class TagResolver
{
    public const MAX_TAGS = 10;

    public function __construct(
        private readonly TagRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
    ) {
    }

    /**
     * Splits a comma separated input into clean, unique tag names.
     *
     * @return list<string>
     */
    public static function parse(?string $input): array
    {
        $names = [];
        foreach (explode(',', (string) $input) as $name) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $name));
            $name = mb_substr($name, 0, 60);
            if ('' !== $name && !\in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true)) {
                $names[] = $name;
            }
        }

        return \array_slice($names, 0, self::MAX_TAGS);
    }

    /**
     * @param list<string> $names
     *
     * @return list<Tag>
     */
    public function resolve(array $names): array
    {
        $tags = [];
        foreach ($names as $name) {
            $slug = $this->slugger->slug($name)->lower()->toString();
            if ('' === $slug) {
                continue;
            }
            $tag = $this->repository->findOneBy(['slug' => $slug]);
            if (null === $tag) {
                foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
                    if ($scheduled instanceof Tag && $scheduled->getSlug() === $slug) {
                        $tag = $scheduled;
                    }
                }
            }
            if (null === $tag) {
                $tag = new Tag($name, $slug);
                $this->entityManager->persist($tag);
            }
            $tags[$slug] = $tag;
        }

        return array_values($tags);
    }
}
