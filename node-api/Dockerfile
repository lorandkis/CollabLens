FROM node:18

WORKDIR /usr/src/app

# Copy package files first for caching
COPY package*.json ./

# Install dependencies
RUN npm install

# Now copy the rest of your app
COPY . .

EXPOSE 4000

CMD ["node", "index.js"]
