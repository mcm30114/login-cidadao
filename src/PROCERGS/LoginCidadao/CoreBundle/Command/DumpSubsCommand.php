<?php
/**
 * This file is part of the login-cidadao project or it's bundles.
 *
 * (c) Guilherme Donato <guilhermednt on github>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PROCERGS\LoginCidadao\CoreBundle\Command;

use LoginCidadao\CoreBundle\Entity\Person;
use LoginCidadao\CoreBundle\Entity\PersonRepository;
use LoginCidadao\OpenIDBundle\Entity\ClientMetadata;
use LoginCidadao\OpenIDBundle\Entity\ClientMetadataRepository;
use LoginCidadao\OpenIDBundle\Service\SubjectIdentifierService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManager;

class DumpSubsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('lc:dump-subs')
            ->addArgument('client', InputArgument::REQUIRED)
            ->addArgument(
                'output',
                InputArgument::OPTIONAL,
                'The output file'
            )
            ->setDescription('Dump all subject identifiers for the given Client');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result = [];
        $file = $input->getArgument('output');
        $clientId = $input->getArgument('client');
        $em = $this->getManager();

        $io = new SymfonyStyle($input, $output);
        $io->title("Dumping Subject Identifiers for client $clientId");

        /** @var ClientMetadataRepository $metadataRepo */
        $metadataRepo = $em->getRepository('LoginCidadaoOpenIDBundle:ClientMetadata');

        /** @var ClientMetadata $metadata */
        $metadata = $metadataRepo->findByClientId($clientId);
        if (!$metadata) {
            $io->error("Client '$clientId' metadata was not found!");
            return;
        }
        $metadata->setSubjectType('pairwise');

        $subService = new SubjectIdentifierService($this->getContainer()->getParameter('secret'));

        /** @var PersonRepository $personRepo */
        $personRepo = $em->getRepository('LoginCidadaoCoreBundle:Person');
        $query = $personRepo->getFindAuthorizedByClientIdQuery($clientId)->getQuery();
        $results = $query->iterate();

        $io->section('Iterating authorized people...');
        if ($file) {
            $handle = fopen($file, 'w+');
            fputcsv($handle, ['cpf', 'pairwise', 'public']);
        }
        $count = 0;
        while (false !== ($row = $results->next())) {
            /** @var Person $person */
            $person = $row[0];
            $sub = $subService->getSubjectIdentifier($person, $metadata);
            $data = ['cpf' => $person->getCpf(), 'pairwise' => $sub, 'public' => $person->getId()];

            if (isset($handle)) {
                fputcsv($handle, [$data['cpf'], $data['pairwise'], $data['public']]);
            } else {
                $result[] = $data;
            }
            $em->detach($person);
            $count++;
        }
        if (isset($handle)) {
            fclose($handle);
        }
        $io->success("$count Subject Identifiers generated");

        if (!$file) {
            $io->table(['CPF', 'Pairwise ID', 'Public ID'], $result);
        }
    }

    /**
     *
     * @return EntityManager
     */
    private function getManager()
    {
        return $this->getContainer()->get('doctrine')->getManager();
    }
}
