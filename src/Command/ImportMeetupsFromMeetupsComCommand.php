<?php declare(strict_types=1);

namespace Fop\Command;

use DateTimeInterface;
use Fop\Location;
use Fop\Meetup;
use Fop\Repository\MeetupRepository;
use Fop\Repository\UserGroupRepository;
use GuzzleHttp\Client;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symplify\PackageBuilder\Console\Command\CommandNaming;

final class ImportMeetupsFromMeetupsComCommand extends Command
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var MeetupRepository
     */
    private $meetupRepository;

    /**
     * @var UserGroupRepository
     */
    private $userGroupRepository;

    /**
     * @var DateTimeInterface
     */
    private $nowDateTime;

    /**
     * @var string
     */
    private $meetupApiKey;

    public function __construct(
        Client $client,
        MeetupRepository $meetupRepository,
        UserGroupRepository $userGroupRepository,
        string $meetupApiKey
    ) {
        parent::__construct();
        $this->client = $client;
        $this->meetupRepository = $meetupRepository;
        $this->userGroupRepository = $userGroupRepository;
        $this->nowDateTime = DateTime::from('now');
        $this->meetupApiKey = $meetupApiKey;
    }

    protected function configure(): void
    {
        $this->setName(CommandNaming::classToName(self::class));
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $europeanUserGroups = $this->userGroupRepository->fetchByContinent('Europe');

        $meetups = [];
        foreach ($europeanUserGroups as $europeanUserGroup) {
            $groupUrlName = $this->resolveGroupUrlNameFromGroupUrl($europeanUserGroup);

            $meetupsOfGroup = $this->getMeetupsForUserGroup($groupUrlName);

            $meetups = array_merge($meetups, $meetupsOfGroup);
        }

        $this->meetupRepository->saveToFile($meetups);
    }

    /**
     * @return Meetup[]
     */
    private function getMeetupsForUserGroup(string $groupName): array
    {
        $url = sprintf('http://api.meetup.com/2/events?group_urlname=%s', $groupName);

        $response = $this->client->request('GET', $url, [
            # see https://www.meetup.com/meetup_api/auth/#keys
            'key' => $this->meetupApiKey,
        ]);

        $result = Json::decode($response->getBody(), Json::FORCE_ARRAY);
        $events = $result['results'];

        $meetups = [];
        foreach ($events as $event) {
            // not sure why, but probably some bug
            $time = substr((string) $event['time'], 0, -3);
            $startDateTime = DateTime::from($time);

            if ($this->shouldSkipMeetup($startDateTime, $event)) {
                continue;
            }

            $meetups[] = $this->createMeetupFromEventData($event, $startDateTime);
        }

        return $meetups;
    }

    /**
     * @param string[] $userGroup
     */
    private function resolveGroupUrlNameFromGroupUrl(array $userGroup): string
    {
        $array = explode('/', $userGroup['meetup_com_url']);
        end($array);

        return prev($array);
    }

    /**
     * @param mixed[] $event
     */
    private function shouldSkipMeetup(DateTimeInterface $startDateTime, array $event): bool
    {
        // skip past meetups
        if ($startDateTime < $this->nowDateTime) {
            return true;
        }

        // draft event, not ready yet
        return ! isset($event['venue']);
    }

    /**
     * @param mixed[] $event
     */
    private function createMeetupFromEventData(array $event, DateTimeInterface $startDateTime): Meetup
    {
        $venue = $event['venue'];

        $location = new Location($venue['city'], $venue['localized_country_name'], $venue['lon'], $venue['lat']);

        return new Meetup($event['name'], $event['group']['name'], $startDateTime, $location);
    }
}