<?php
declare(strict_types=1);

namespace App\Models;

/** A catalog product. Its JSON form is exactly what the API returns for each result. */
final class Product implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $brand,
        public readonly string $category,
        public readonly string $subcategory,
        public readonly string $description,
        public readonly string $attributes,
        public readonly string $imageUrl,
        public readonly float $price,
    ) {
    }

    /** @param array<string, mixed> $row a products table row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            brand: $row['brand'] === '' ? null : $row['brand'],
            category: $row['category'],
            subcategory: $row['subcategory'],
            description: $row['description'],
            attributes: $row['attributes'],
            imageUrl: $row['image_url'],
            price: (float) $row['price'],
        );
    }

    /** @return array<string, mixed> column => value, for inserts */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'description' => $this->description,
            'attributes' => $this->attributes,
            'image_url' => $this->imageUrl,
            'price' => $this->price,
        ];
    }

    /** The public shape: what a search result card needs, without long text fields. */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'image_url' => $this->imageUrl,
            'price' => $this->price,
        ];
    }
}
