import random
import csv
import string

def random_sku():
    return ''.join(random.choices(string.ascii_uppercase + string.digits, k=8))

def random_product_name():
    adjectives = ["Smart", "Ultra", "Pro", "Eco", "Turbo", "Prime", "Next", "Elite"]
    nouns = ["Phone", "Bottle", "Laptop", "Watch", "Backpack", "Chair", "Headset", "Lamp"]
    return f"{random.choice(adjectives)} {random.choice(nouns)}"

def generate_data(rows=20, output_file="products.csv"):
    data = []
    for _ in range(rows):
        sku = random_sku()
        name = random_product_name()
        quantity = random.randint(1, 200)
        price = round(random.uniform(5, 500), 2)
        data.append([sku, name, quantity, price])

    with open(output_file, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["sku", "product_name", "quantity", "price"])
        writer.writerows(data)

    return data

if __name__ == "__main__":
    generate_data(5000000)
