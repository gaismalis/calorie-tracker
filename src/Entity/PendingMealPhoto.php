<?php

namespace App\Entity;

use App\Ai\Photo;
use App\Repository\PendingMealPhotoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A meal's photo, kept only until the meal is estimated: background retries need it, nothing else does.
 * Deleted as soon as an estimate succeeds, and with the meal (database cascade).
 */
#[ORM\Entity(repositoryClass: PendingMealPhotoRepository::class)]
class PendingMealPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private MealEntry $entry;

    /** @var string|resource */
    #[ORM\Column(type: Types::BLOB)]
    private mixed $bytes;

    #[ORM\Column(length: 32)]
    private string $mimeType;

    public function __construct(MealEntry $entry, Photo $photo)
    {
        $this->entry = $entry;
        $this->bytes = $photo->bytes;
        $this->mimeType = $photo->mimeType;
    }

    public function getEntry(): MealEntry
    {
        return $this->entry;
    }

    public function getPhoto(): Photo
    {
        if (is_resource($this->bytes)) { // read back from the database as a stream
            rewind($this->bytes);
            $this->bytes = stream_get_contents($this->bytes);
        }

        return new Photo($this->bytes, $this->mimeType);
    }
}
