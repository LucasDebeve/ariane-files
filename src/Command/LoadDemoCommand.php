<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ChangeRequest;
use App\Entity\Document;
use App\Entity\Folder;
use App\Enum\ChangeRequestType;
use App\Service\TagResolver;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:demo:load', description: 'Loads demonstration folders and documents (development only).')]
final class LoadDemoCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentStorage $storage,
        private readonly TagResolver $tagResolver,
        private readonly SluggerInterface $slugger,
        #[Autowire(param: 'kernel.environment')] private readonly string $environment,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ('prod' === $this->environment) {
            $io->error('Demo data cannot be loaded in production.');

            return Command::FAILURE;
        }

        $tree = [
            'BAFA' => ['Formation générale', 'Approfondissement'],
            'BAFD' => ['Formation générale', 'Perfectionnement'],
            'Réglementation' => [],
            'Jeux et animations' => ['Grands jeux', 'Veillées'],
        ];
        $folders = [];
        foreach ($tree as $root => $children) {
            $rootFolder = $this->folder($root, null);
            $folders[$root] = $rootFolder;
            foreach ($children as $child) {
                $folders[$root.'/'.$child] = $this->folder($child, $rootFolder);
            }
        }
        $this->entityManager->flush();

        $documents = [
            ['Grille d\'évaluation stagiaire BAFA', 'BAFA/Formation générale', ['évaluation', 'stagiaire'], 'Critères d\'évaluation des stagiaires en session de formation générale : posture, sécurité, relation aux enfants.'],
            ['Projet pédagogique type accueil de loisirs', 'BAFD/Formation générale', ['projet pédagogique', 'direction'], 'Modèle commenté de projet pédagogique pour un accueil collectif de mineurs.'],
            ['Taux d\'encadrement et réglementation ACM', 'Réglementation', ['réglementation', 'sécurité'], 'Synthèse des taux d\'encadrement, qualifications et obligations déclaratives des accueils collectifs de mineurs.'],
            ['Grand jeu : la chasse au trésor des pirates', 'Jeux et animations/Grands jeux', ['grand jeu', '8-12 ans'], 'Déroulé complet, matériel et variantes pour un grand jeu de 2 heures.'],
            ['Veillée contes et légendes', 'Jeux et animations/Veillées', ['veillée', 'imaginaire'], 'Une veillée calme autour des contes, avec ambiance sonore et rituels.'],
            ['Diaporama : rythmes de l\'enfant', 'BAFA/Approfondissement', ['rythmes', 'enfant'], 'Support de séquence sur les besoins et rythmes de l\'enfant selon l\'âge.'],
            ['Fiche sanitaire et PAI', 'Réglementation', ['sanitaire', 'sécurité'], 'Rappels sur la fiche sanitaire de liaison, les PAI et le rôle de l\'assistant sanitaire.'],
            ['Bilan de session directeur', 'BAFD/Perfectionnement', ['bilan', 'direction'], 'Trame de bilan de session de perfectionnement BAFD.'],
        ];
        foreach ($documents as [$title, $folderKey, $tags, $description]) {
            $document = (new Document())
                ->setTitle($title)
                ->setDescription($description)
                ->setFolder($folders[$folderKey])
                ->setTags($this->tagResolver->resolve($tags));
            $pdf = self::pdf($title, $description);
            $key = Uuid::v4()->toRfc4122();
            $this->storage->write(StorageArea::Published, $key, $pdf);
            $filename = $this->slugger->slug($title)->lower()->toString().'.pdf';
            $document->attachFile($key, $filename, 'application/pdf', \strlen($pdf), hash('sha256', $pdf));
            $document->setExtractedText($title."\n".$description);
            $this->entityManager->persist($document);
        }

        $video = (new Document())
            ->setTitle('Vidéo : gestes de premiers secours')
            ->setDescription('Rappel des gestes qui sauvent, à visionner avant la session.')
            ->setFolder($folders['Réglementation'])
            ->setTags($this->tagResolver->resolve(['sécurité', 'premiers secours']))
            ->attachVideoLink('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->entityManager->persist($video);

        $this->entityManager->persist(new ChangeRequest(ChangeRequestType::Addition, 'Camille', [
            'title' => 'Jeu de piste nature',
            'description' => 'Un jeu de piste pour les 6-8 ans en forêt.',
            'folderId' => $folders['Jeux et animations/Grands jeux']->getId(),
            'tags' => ['grand jeu', 'nature'],
            'videoUrl' => 'https://vimeo.com/76979871',
        ]));
        $this->entityManager->flush();

        $io->success('Demo data loaded.');

        return Command::SUCCESS;
    }

    private function folder(string $name, ?Folder $parent): Folder
    {
        $folder = (new Folder())->setName($name)->setParent($parent);
        $folder->setSlug($this->slugger->slug($folder->getPath())->lower()->toString());
        $this->entityManager->persist($folder);

        return $folder;
    }

    /**
     * Minimal valid one-page PDF.
     */
    public static function pdf(string $title, string $body): string
    {
        $escape = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $s));
        $lines = ['BT /F1 22 Tf 60 760 Td ('.$escape($title).') Tj ET'];
        $y = 720;
        foreach (explode("\n", wordwrap($body, 80)) as $line) {
            $lines[] = 'BT /F1 12 Tf 60 '.$y.' Td ('.$escape($line).') Tj ET';
            $y -= 18;
        }
        $stream = implode("\n", $lines);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.\strlen($stream).' >>'."\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 ".(\count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(\count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
