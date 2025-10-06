<?php

namespace App\Entity;

use App\Repository\ClubRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ClubRepository::class)]
class Club
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $logo = null;

    #[ORM\Column(length: 255)]
    private ?string $address = null;

    #[ORM\Column(length: 255)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 255)]
    private ?string $city = null;

    #[ORM\Column(length: 255)]
    private ?string $presidentName = null;

    #[ORM\Column(length: 255)]
    private ?string $treasurerName = null;

    /**
     * @var Collection<int, Order>
     */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: "club")]
    private Collection $orders;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $country = null;

    /**
     * @var Collection<int, Member>
     */
    #[ORM\OneToMany(targetEntity: Member::class, mappedBy: "club")]
    private Collection $members;

    #[ORM\Column(length: 255)]
    private ?string $clubNumber = null;

    #[ORM\Column(length: 9, nullable: true)]
    #[Assert\Regex(pattern: "/^\d{4}-\d{4}$/", message: "Format attendu : AAAA-AAAA (ex : 2024-2025).")]
    private ?string $sportSeason = null;

    public function __construct()
    {
        $this->orders = new ArrayCollection();
        $this->members = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getPresidentName(): ?string
    {
        return $this->presidentName;
    }

    public function setPresidentName(string $presidentName): static
    {
        $this->presidentName = $presidentName;

        return $this;
    }

    public function getTreasurerName(): ?string
    {
        return $this->treasurerName;
    }

    public function setTreasurerName(string $treasurerName): static
    {
        $this->treasurerName = $treasurerName;

        return $this;
    }

    /**
     * @return Collection<int, Order>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): static
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setClub($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): static
    {
        if ($this->orders->removeElement($order)) {
            // set the owning side to null (unless already changed)
            if ($order->getClub() === $this) {
                $order->setClub(null);
            }
        }

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;

        return $this;
    }

    /**
     * @return Collection<int, Member>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(Member $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setClub($this);
        }

        return $this;
    }

    public function removeMember(Member $member): static
    {
        if ($this->members->removeElement($member)) {
            // set the owning side to null (unless already changed)
            if ($member->getClub() === $this) {
                $member->setClub(null);
            }
        }

        return $this;
    }

    public function getClubNumber(): ?string
    {
        return $this->clubNumber;
    }

    public function setClubNumber(string $clubNumber): static
    {
        $this->clubNumber = $clubNumber;

        return $this;
    }

    #[ORM\PrePersist]
    public function initSportSeasonByDefault(): void
    {
        if (null === $this->sportSeason) {
            $this->sportSeason = self::seasonFromDate(new \DateTimeImmutable());
        }
    }

    #[Assert\Callback]
    public function validateSportSeasonContiguity(ExecutionContextInterface $context): void
    {
        if (!$this->sportSeason) {
            return;
        }
        if (preg_match("/^(\d{4})-(\d{4})$/", $this->sportSeason, $m)) {
            if ((int)$m[2] !== (int)$m[1] + 1) {
                $context->buildViolation("La seconde année doit être exactement la première + 1.")
                    ->atPath("sportSeason")->addViolation();
            }
        }
    }

    public static function seasonFromDate(\DateTimeInterface $date): string
    {
        $y = (int)$date->format("Y");
        $m = (int)$date->format("n");
        return $m >= 7 ? sprintf("%d-%d", $y, $y + 1) : sprintf("%d-%d", $y - 1, $y);
    }

    public function getSportSeason(): ?string
    {
        return $this->sportSeason;
    }

    public function setSportSeason(string $sportSeason): static
    {
        $this->sportSeason = $sportSeason;

        $members = $this->getMembers();

        foreach ($members as $member) {
            $member->setSportSeason($sportSeason);
        }

        return $this;
    }
}
